// frontend/components/CampaignBuilder.tsx
// Loopza Amplify campaign form.
// This component saves a DRAFT through the supplied application callback.
// It does not publish ads to external platforms.

import React, { useState } from 'react';

export type LoopzaAdChannel =
  | 'FACEBOOK'
  | 'INSTAGRAM'
  | 'GOOGLE'
  | 'TIKTOK'
  | 'YOUTUBE';

export interface CreateCampaignInput {
  name: string;
  channel: LoopzaAdChannel;
  budget: number;
  budgetCurrency: string;
}

export interface CampaignBuilderProps {
  // Connect this to an authenticated backend request. The server must derive
  // the business workspace from the authenticated session and authorize access.
  onCreateCampaign: (input: CreateCampaignInput) => Promise<void>;
  budgetCurrency?: string;
}

const CHANNELS: { value: LoopzaAdChannel; label: string }[] = [
  { value: 'FACEBOOK', label: 'Facebook' },
  { value: 'INSTAGRAM', label: 'Instagram' },
  { value: 'GOOGLE', label: 'Google Ads' },
  { value: 'TIKTOK', label: 'TikTok' },
  { value: 'YOUTUBE', label: 'YouTube' },
];

export function CampaignBuilder({
  onCreateCampaign,
  budgetCurrency = 'USD',
}: CampaignBuilderProps) {
  const [campaignName, setCampaignName] = useState('');
  const [budget, setBudget] = useState('');
  const [channel, setChannel] = useState<LoopzaAdChannel>('FACEBOOK');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const [successMessage, setSuccessMessage] = useState('');

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setErrorMessage('');
    setSuccessMessage('');

    const normalizedName = campaignName.trim();
    const numericBudget = Number(budget);
    const currency = budgetCurrency.trim().toUpperCase();

    if (!normalizedName) {
      setErrorMessage('Enter a campaign name.');
      return;
    }

    if (!Number.isFinite(numericBudget) || numericBudget <= 0) {
      setErrorMessage('Enter a budget greater than zero.');
      return;
    }

    if (!/^[A-Z]{3}$/.test(currency)) {
      setErrorMessage('The workspace currency must be a valid 3-letter currency code.');
      return;
    }

    setIsSubmitting(true);

    try {
      await onCreateCampaign({
        name: normalizedName,
        channel,
        budget: numericBudget,
        budgetCurrency: currency,
      });

      setCampaignName('');
      setBudget('');
      setSuccessMessage(
        'Campaign draft saved. It has not been published to the advertising platform.',
      );
    } catch (error) {
      setErrorMessage(
        error instanceof Error
          ? error.message
          : 'The campaign could not be saved. Please try again.',
      );
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <section className="mx-auto w-full max-w-xl rounded-lg bg-white p-6 text-gray-900 shadow-md">
      <h2 className="mb-2 text-2xl font-bold">
        Loopza Amplify Campaign Builder
      </h2>
      <p className="mb-5 text-sm text-gray-600">
        Create a campaign draft. Publishing requires a connected advertising
        account and a separate approval step.
      </p>

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label
            htmlFor="loopza-campaign-name"
            className="mb-1 block text-sm font-medium"
          >
            Campaign name
          </label>
          <input
            id="loopza-campaign-name"
            type="text"
            value={campaignName}
            onChange={(event) => setCampaignName(event.target.value)}
            className="w-full rounded border border-gray-300 p-2"
            placeholder="e.g. Summer promotion"
            maxLength={120}
            autoComplete="off"
            required
          />
        </div>

        <div>
          <label
            htmlFor="loopza-campaign-channel"
            className="mb-1 block text-sm font-medium"
          >
            Advertising channel
          </label>
          <select
            id="loopza-campaign-channel"
            value={channel}
            onChange={(event) =>
              setChannel(event.target.value as LoopzaAdChannel)
            }
            className="w-full rounded border border-gray-300 p-2"
          >
            {CHANNELS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label
            htmlFor="loopza-campaign-budget"
            className="mb-1 block text-sm font-medium"
          >
            Campaign budget ({budgetCurrency.toUpperCase()})
          </label>
          <input
            id="loopza-campaign-budget"
            type="number"
            value={budget}
            onChange={(event) => setBudget(event.target.value)}
            className="w-full rounded border border-gray-300 p-2"
            placeholder="500"
            min="0.01"
            step="0.01"
            inputMode="decimal"
            required
          />
        </div>

        {errorMessage && (
          <p className="text-sm text-red-700" role="alert">
            {errorMessage}
          </p>
        )}

        {successMessage && (
          <p className="text-sm text-green-700" role="status">
            {successMessage}
          </p>
        )}

        <button
          type="submit"
          disabled={isSubmitting}
          className="w-full rounded bg-blue-600 p-2 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSubmitting ? 'Saving draft…' : 'Save campaign draft'}
        </button>
      </form>
    </section>
  );
}
