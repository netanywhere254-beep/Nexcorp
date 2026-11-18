<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('trace_profiles', function(Blueprint $t) {
   $t->id(); $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
   $t->char('country_code',2)->index(); $t->string('region_code',64)->nullable()->index();
   $t->string('locality',120)->nullable(); $t->string('account_type',24)->default('family')->index();
   $t->string('verification_status',24)->default('pending')->index();
   $t->string('phone_verification_status',24)->default('pending');
   $t->string('location_verification_status',24)->default('pending');
   $t->string('selfie_verification_status',24)->default('pending');
   $t->string('selfie_path')->nullable(); $t->decimal('verified_latitude',10,7)->nullable();
   $t->decimal('verified_longitude',10,7)->nullable(); $t->decimal('submitted_latitude',10,7)->nullable();\n   $t->decimal('submitted_longitude',10,7)->nullable(); $t->timestamp('verified_at')->nullable(); $t->timestamps();
  });
  Schema::create('trace_cases', function(Blueprint $t) {
   $t->id(); $t->uuid('public_id')->unique(); $t->string('case_reference',40)->unique();
   $t->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
   $t->char('reporter_country_code',2); $t->string('reporter_region_code',64)->nullable();
   $t->char('incident_country_code',2); $t->string('incident_region_code',64)->nullable();
   $t->string('incident_locality',120)->nullable(); $t->string('case_type',32)->default('missing_person')->index();
   $t->string('subject_name',160); $t->unsignedSmallInteger('subject_age')->nullable();
   $t->string('subject_description',2000); $t->text('last_seen_details'); $t->timestamp('last_seen_at')->nullable();
   $t->string('status',32)->default('pending_review')->index(); $t->string('publication_status',24)->default('private')->index();
   $t->boolean('urgent')->default(false); $t->timestamp('authority_reported_at')->nullable();
   $t->string('authority_reference',120)->nullable(); $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('reviewed_at')->nullable(); $t->timestamps();
   $t->index(['incident_country_code','incident_region_code','status'],'trace_case_jurisdiction_idx');
   $t->index(['reporter_user_id','created_at']);
  });
  Schema::create('trace_case_documents', function(Blueprint $t) {
   $t->id(); $t->foreignId('trace_case_id')->constrained()->cascadeOnDelete();
   $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
   $t->string('document_type',32); $t->string('storage_path'); $t->string('original_filename',255);
   $t->string('mime_type',120); $t->unsignedBigInteger('size_bytes'); $t->char('sha256',64);
   $t->string('verification_status',24)->default('pending')->index(); $t->text('review_notes')->nullable(); $t->timestamps();
   $t->index(['trace_case_id','document_type']);
  });
  Schema::create('trace_case_access_grants', function(Blueprint $t) {
   $t->id(); $t->foreignId('trace_case_id')->constrained()->cascadeOnDelete();
   $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->string('access_role',24);
   $t->string('granted_by_type',24); $t->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
   $t->timestamp('expires_at')->nullable(); $t->timestamp('revoked_at')->nullable(); $t->timestamps();
   $t->unique(['trace_case_id','user_id','access_role'],'trace_case_user_role_unique');
   $t->index(['user_id','revoked_at']);
  });
 }
 public function down(): void {
  Schema::dropIfExists('trace_case_access_grants'); Schema::dropIfExists('trace_case_documents');
  Schema::dropIfExists('trace_cases'); Schema::dropIfExists('trace_profiles');
 }
};