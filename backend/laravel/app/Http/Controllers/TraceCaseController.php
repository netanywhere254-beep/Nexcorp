<?php
namespace App\Http\Controllers;
use App\Models\TraceCase;
use App\Models\TraceCaseDocument;
use App\Services\CaseReferenceNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\TraceCaseResubmission;
use App\Models\AuditLog;
use Illuminate\Validation\Rule;
class TraceCaseController extends Controller {
 public function store(Request $request): JsonResponse {
  $user=$request->user(); $profile=$user->traceProfile;
  if (!$profile || $profile->account_type!=='family') return response()->json(['message'=>'An individual family profile is required.'],403);
  if ($profile->phone_verification_status !== 'verified' || !filled($user->phone)) {
   return response()->json(['message'=>'Verify your contact phone number by OTP before registering a case.'], 403);
  }
  $v=$request->validate([
   'incident_country_code'=>['required','string','size:2'],'incident_region_code'=>['nullable','string','max:64'],
   'incident_locality'=>['nullable','string','max:120'],'case_type'=>['sometimes',Rule::in(['missing_person','unidentified_person'])],
   'subject_name'=>['required','string','max:160'],'subject_age'=>['nullable','integer','min:0','max:120'],
   'subject_description'=>['required','string','max:2000'],'last_seen_details'=>['required','string','max:5000'],
   'last_seen_at'=>['nullable','date','before_or_equal:now'],'urgent'=>['sometimes','boolean'],
   'authority_reference'=>['nullable','string','max:120'],'authority_reported_at'=>['nullable','date','before_or_equal:now'],
   'ob_document'=>['nullable','file','mimes:pdf,jpg,jpeg,png','max:10240'],
  ]);
  $sameCountry=strtoupper($v['incident_country_code'])===strtoupper($profile->country_code);
  $sameRegion=$sameCountry && filled($profile->region_code) && filled($v['incident_region_code']??null)
   && hash_equals((string)$profile->region_code,(string)$v['incident_region_code']);
  $locallyVerified=$sameRegion && $profile->verification_status==='verified'
   && $profile->phone_verification_status==='verified'
   && $profile->location_verification_status==='verified'
   && $profile->selfie_verification_status==='verified';
  $urgent=(bool)($v['urgent']??false);
  $case=TraceCase::create([
   'public_id'=>(string)Str::uuid(),'case_reference'=>app(CaseReferenceNumber::class)->next('loopza'),
   'reporter_user_id'=>$user->id,'reporter_phone'=>$user->phone,'reporter_country_code'=>strtoupper($profile->country_code),
   'reporter_region_code'=>$profile->region_code,'incident_country_code'=>strtoupper($v['incident_country_code']),
   'incident_region_code'=>$v['incident_region_code']??null,'incident_locality'=>$v['incident_locality']??null,
   'case_type'=>$v['case_type']??'missing_person','subject_name'=>$v['subject_name'],'subject_age'=>$v['subject_age']??null,
   'subject_description'=>$v['subject_description'],'last_seen_details'=>$v['last_seen_details'],
   'last_seen_at'=>$v['last_seen_at']??null,'status'=>$locallyVerified?'pending_review':($urgent?'urgent_provisional_review':'cross_region_review'),
   'publication_status'=>'private','urgent'=>$urgent,'authority_reference'=>$v['authority_reference']??null,
   'authority_reported_at'=>$v['authority_reported_at']??null,
  ]);
  if ($request->hasFile('ob_document')) {
   $file=$request->file('ob_document'); $path=$file->store("trace-find/cases/{$case->public_id}/restricted",'local');
   TraceCaseDocument::create([
    'trace_case_id'=>$case->id,'uploaded_by'=>$user->id,'document_type'=>'authority_report',
    'storage_path'=>$path,'original_filename'=>basename($file->getClientOriginalName()),
    'mime_type'=>(string)$file->getMimeType(),'size_bytes'=>(int)$file->getSize(),
    'sha256'=>hash_file('sha256',$file->getRealPath()),'verification_status'=>'pending',
   ]);
  }
  return response()->json(['message'=>'Report received for review. It is not publicly visible.',
   'case'=>['reference'=>$case->case_reference,'status'=>$case->status,'publication_status'=>$case->publication_status,
   'incident_country_code'=>$case->incident_country_code,'incident_region_code'=>$case->incident_region_code]],201);
 }

 public function resubmit(Request $request, int $caseId): JsonResponse {
  $data=$request->validate([
   'reason'=>['required','string','min:10','max:2000'],
   'subject_name'=>['sometimes','required','string','max:160'],
   'subject_age'=>['sometimes','nullable','integer','min:0','max:120'],
   'subject_description'=>['sometimes','required','string','max:2000'],
   'last_seen_details'=>['sometimes','required','string','max:5000'],
   'incident_region_code'=>['sometimes','nullable','string','max:64'],
   'incident_locality'=>['sometimes','nullable','string','max:120'],
   'last_seen_at'=>['sometimes','nullable','date','before_or_equal:now'],
   'urgent'=>['sometimes','boolean'],
   'ob_document'=>['nullable','file','mimes:pdf,jpg,jpeg,png','max:10240'],
  ]);
  $case=TraceCase::query()->findOrFail($caseId);
  abort_unless((int)$case->reporter_user_id===(int)$request->user()->id,404);
  abort_unless(in_array($case->status,['rejected','needs_more_information'],true),409,'Only rejected or information-requested cases can be resubmitted.');
  $storedPath=null;
  if ($request->hasFile('ob_document')) {
   $storedPath=$request->file('ob_document')->store("trace-find/cases/{$case->public_id}/restricted",'local');
  }
  try {
   $case=DB::transaction(function () use ($request,$caseId,$data,$storedPath): TraceCase {
    $locked=TraceCase::query()->lockForUpdate()->findOrFail($caseId);
    abort_unless((int)$locked->reporter_user_id===(int)$request->user()->id,404);
    abort_unless(in_array($locked->status,['rejected','needs_more_information'],true),409,'This case is no longer eligible for resubmission.');
    $snapshot=[
     'status'=>$locked->status,'publication_status'=>$locked->publication_status,
     'reviewed_by'=>$locked->reviewed_by,'reviewed_at'=>$locked->reviewed_at?->toISOString(),
     'review_notes'=>$locked->review_notes,
     'subject_name'=>$locked->subject_name,'subject_age'=>$locked->subject_age,
     'subject_description'=>$locked->subject_description,'last_seen_details'=>$locked->last_seen_details,
     'incident_region_code'=>$locked->incident_region_code,'incident_locality'=>$locked->incident_locality,
     'last_seen_at'=>$locked->last_seen_at?->toISOString(),
    ];
    TraceCaseResubmission::create([
     'trace_case_id'=>$locked->id,'submitted_by'=>$request->user()->id,
     'reason'=>$data['reason'],'previous_review'=>$snapshot,
    ]);
    $updates=[];
    foreach (['subject_name','subject_age','subject_description','last_seen_details','incident_region_code','incident_locality','last_seen_at','urgent'] as $field) {
     if (array_key_exists($field,$data)) $updates[$field]=$data[$field];
    }
    $updates['status']='pending_review';
    $updates['publication_status']='private';
    $updates['reviewed_by']=null;
    $updates['reviewed_at']=null;
    $updates['review_notes']=null;
    $locked->update($updates);
    if ($storedPath) {
     $file=$request->file('ob_document');
     $locked->documents()->create([
      'uploaded_by'=>$request->user()->id,'document_type'=>'supplementary_evidence',
      'storage_path'=>$storedPath,'original_filename'=>basename($file->getClientOriginalName()),
      'mime_type'=>(string)$file->getMimeType(),'size_bytes'=>(int)$file->getSize(),
      'sha256'=>hash_file('sha256',$file->getRealPath()),'verification_status'=>'pending',
     ]);
    }
    AuditLog::create([
     'organization_id'=>null,'actor_user_id'=>$request->user()->id,
     'action'=>'loopza.trace_case.resubmitted','subject_type'=>TraceCase::class,
     'subject_id'=>(string)$locked->id,
     'metadata'=>['case_reference'=>$locked->case_reference,'reason'=>$data['reason'],'new_evidence_added'=>(bool)$storedPath],
    ]);
    return $locked->fresh();
   });
  } catch (\Throwable $exception) {
   if ($storedPath) Storage::disk('local')->delete($storedPath);
   throw $exception;
  }
  return response()->json([
   'message'=>'Case resubmitted for human review. It remains private.',
   'case'=>['reference'=>$case->case_reference,'status'=>$case->status,'publication_status'=>$case->publication_status],
  ]);
 }

}
