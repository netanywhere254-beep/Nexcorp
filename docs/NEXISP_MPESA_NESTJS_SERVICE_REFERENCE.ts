/**
 * NexISP M-Pesa Daraja STK Push — NestJS reference implementation.
 *
 * STATUS: Reference code only. The current Nexcorp application direction is
 * Laravel + MySQL. This NestJS service is not wired into the active application.
 * If a separate NestJS integration service is approved, move this file into that
 * service, install axios, configure environment secrets, and test in Daraja
 * sandbox before requesting production credentials.
 *
 * REQUIRED ENVIRONMENT:
 * MPESA_ENV=sandbox                 # sandbox | production
 * MPESA_CONSUMER_KEY=...
 * MPESA_CONSUMER_SECRET=...
 * MPESA_SHORTCODE=174379            # sandbox example; configure your own
 * MPESA_PASSKEY=...
 * MPESA_CALLBACK_URL=https://...    # publicly reachable HTTPS callback endpoint
 *
 * SECURITY / PAYMENT RULES:
 * - Keep credentials in a secret manager or protected environment variables.
 * - Never commit .env files or log access tokens, passkeys, phone numbers,
 *   request passwords, or raw provider payloads containing personal data.
 * - Authenticate/validate callback requests as far as Daraja permits, then
 *   reconcile callback IDs and amounts against the pending transaction.
 * - Process callbacks idempotently; duplicate callbacks must not double-credit.
 * - An accepted STK Push request is NOT proof of payment. Activate a service
 *   only after a verified successful callback and a committed payment record.
 * - Apply tenant authorization, rate limits, amount/ownership checks, and audit
 *   logging in the calling application before initiating a payment.
 */
import { Injectable, HttpException, HttpStatus, Logger } from '@nestjs/common';
import axios from 'axios';

type MpesaEnvironment = 'sandbox' | 'production';

interface DarajaTokenResponse {
  access_token: string;
  expires_in?: string;
}

@Injectable()
export class MpesaService {
  private readonly logger = new Logger(MpesaService.name);
  private cachedAccessToken?: string;
  private tokenExpiresAt = 0;

  private get environment(): MpesaEnvironment {
    const value = (process.env.MPESA_ENV || 'sandbox').toLowerCase();
    if (value !== 'sandbox' && value !== 'production') {
      throw new HttpException(
        'Invalid M-Pesa environment configuration',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }
    return value;
  }

  private get baseUrl(): string {
    return this.environment === 'production'
      ? 'https://api.safaricom.co.ke'
      : 'https://sandbox.safaricom.co.ke';
  }

  private getRequiredEnv(name: string): string {
    const value = process.env[name]?.trim();
    if (!value) {
      this.logger.error(`Missing required M-Pesa configuration: ${name}`);
      throw new HttpException(
        'M-Pesa is not configured',
        HttpStatus.SERVICE_UNAVAILABLE,
      );
    }
    return value;
  }

  private async getAccessToken(): Promise<string> {
    // Keep a small safety margin before the token's reported expiry.
    if (this.cachedAccessToken && Date.now() < this.tokenExpiresAt - 30_000) {
      return this.cachedAccessToken;
    }

    const consumerKey = this.getRequiredEnv('MPESA_CONSUMER_KEY');
    const consumerSecret = this.getRequiredEnv('MPESA_CONSUMER_SECRET');
    const auth = Buffer.from(`${consumerKey}:${consumerSecret}`).toString('base64');

    try {
      const response = await axios.get<DarajaTokenResponse>(
        `${this.baseUrl}/oauth/v1/generate?grant_type=client_credentials`,
        {
          headers: { Authorization: `Basic ${auth}` },
          timeout: 10_000,
        },
      );

      if (!response.data?.access_token) {
        throw new Error('Daraja returned no access token');
      }

      const expiresInSeconds = Number(response.data.expires_in || 3600);
      this.cachedAccessToken = response.data.access_token;
      this.tokenExpiresAt = Date.now() + expiresInSeconds * 1000;
      return this.cachedAccessToken;
    } catch {
      this.logger.error('M-Pesa OAuth authentication failed.');
      throw new HttpException(
        'Failed to authenticate with M-Pesa',
        HttpStatus.BAD_GATEWAY,
      );
    }
  }

  /**
   * Daraja expects YYYYMMDDHHmmss in East Africa Time.
   * This avoids relying on the server machine's configured timezone.
   */
  private getTimestamp(): string {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Africa/Nairobi',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date());

    const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    return `${values.year}${values.month}${values.day}${values.hour}${values.minute}${values.second}`;
  }

  private normalizePhoneNumber(phone: string): string {
    const digits = phone.trim().replace(/[\s()+-]/g, '');
    let normalized = digits;

    if (normalized.startsWith('0')) {
      normalized = `254${normalized.slice(1)}`;
    } else if (normalized.startsWith('7') || normalized.startsWith('1')) {
      normalized = `254${normalized}`;
    }

    if (!/^254[17]\d{8}$/.test(normalized)) {
      throw new HttpException(
        'Enter a valid Kenyan Safaricom-compatible phone number',
        HttpStatus.BAD_REQUEST,
      );
    }

    return normalized;
  }

  private validateAccountReference(accountReference: string): string {
    const reference = accountReference?.trim();
    // Daraja AccountReference has a short field limit; use a concise invoice
    // or customer reference rather than a full description.
    if (!reference || reference.length > 12 || !/^[A-Za-z0-9_-]+$/.test(reference)) {
      throw new HttpException(
        'Account reference must be 1–12 letters, numbers, hyphens or underscores',
        HttpStatus.BAD_REQUEST,
      );
    }
    return reference;
  }

  async initiateStkPush(
    phone: string,
    amount: number,
    accountReference: string,
  ): Promise<Record<string, any>> {
    if (!Number.isSafeInteger(amount) || amount < 1) {
      throw new HttpException(
        'M-Pesa amount must be a positive whole number of Kenya shillings',
        HttpStatus.BAD_REQUEST,
      );
    }

    const normalizedPhone = this.normalizePhoneNumber(phone);
    const reference = this.validateAccountReference(accountReference);
    const token = await this.getAccessToken();
    const timestamp = this.getTimestamp();
    const shortcode = this.getRequiredEnv('MPESA_SHORTCODE');
    const passkey = this.getRequiredEnv('MPESA_PASSKEY');
    const callbackUrl = this.getRequiredEnv('MPESA_CALLBACK_URL');

    let parsedCallbackUrl: URL;
    try {
      parsedCallbackUrl = new URL(callbackUrl);
    } catch {
      throw new HttpException(
        'M-Pesa callback URL is invalid',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }

    if (parsedCallbackUrl.protocol !== 'https:') {
      throw new HttpException(
        'M-Pesa callback URL must use HTTPS',
        HttpStatus.INTERNAL_SERVER_ERROR,
      );
    }

    const password = Buffer.from(
      `${shortcode}${passkey}${timestamp}`,
    ).toString('base64');

    const payload = {
      BusinessShortCode: shortcode,
      Password: password,
      Timestamp: timestamp,
      TransactionType: 'CustomerPayBillOnline',
      Amount: amount,
      PartyA: normalizedPhone,
      PartyB: shortcode,
      PhoneNumber: normalizedPhone,
      CallBackURL: callbackUrl,
      AccountReference: reference,
      TransactionDesc: 'NexISP payment',
    };

    try {
      const response = await axios.post(
        `${this.baseUrl}/mpesa/stkpush/v1/processrequest`,
        payload,
        {
          headers: { Authorization: `Bearer ${token}` },
          timeout: 20_000,
        },
      );

      // Persist MerchantRequestID and CheckoutRequestID with a PENDING
      // transaction in the caller before waiting for the callback.
      return response.data;
    } catch {
      this.logger.error('M-Pesa STK Push request failed.');
      throw new HttpException(
        'Failed to initiate M-Pesa STK Push',
        HttpStatus.BAD_GATEWAY,
      );
    }
  }
}
