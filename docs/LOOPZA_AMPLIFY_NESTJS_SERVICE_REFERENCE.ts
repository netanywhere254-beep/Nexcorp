// Loopza Amplify campaign service — NestJS/Prisma reference.
// STATUS: Reference implementation only; not wired into the active Laravel +
// MySQL application. The Prisma model must be generated from
// LOOPZA_PRISMA_SCHEMA_REFERENCE.prisma before this service can compile.
//
// Security boundary:
// - Controllers/guards must authenticate the caller and verify their role in
//   the requested business workspace before calling this service.
// - businessId must come from trusted, authorized server-side context, not an
//   untrusted request body.
// - Never allow a user to change campaign status or budgets without the
//   relevant workspace permission and an audit event.

import {
  BadRequestException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { AdChannel, CampaignStatus, Prisma } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';

export interface CreateAmplifyCampaignInput {
  name: string;
  channel: AdChannel;
  budget: number;
  budgetCurrency?: string;
  objective?: string;
  startsAt?: Date;
  endsAt?: Date;
}

const ALLOWED_STATUS_CHANGES: CampaignStatus[] = [
  CampaignStatus.ACTIVE,
  CampaignStatus.PAUSED,
  CampaignStatus.COMPLETED,
];

@Injectable()
export class AmplifyService {
  constructor(private readonly prisma: PrismaService) {}

  async createCampaign(
    businessId: string,
    data: CreateAmplifyCampaignInput,
  ) {
    if (!businessId?.trim()) {
      throw new BadRequestException('A valid business workspace is required.');
    }

    const name = data.name?.trim();
    if (!name) {
      throw new BadRequestException('Campaign name is required.');
    }

    if (!Number.isFinite(data.budget) || data.budget <= 0) {
      throw new BadRequestException('Campaign budget must be a positive amount.');
    }

    const currency = (data.budgetCurrency ?? 'USD').toUpperCase();
    if (!/^[A-Z]{3}$/.test(currency)) {
      throw new BadRequestException('Currency must be a valid 3-letter code.');
    }

    if (
      data.startsAt &&
      data.endsAt &&
      data.endsAt.getTime() <= data.startsAt.getTime()
    ) {
      throw new BadRequestException('Campaign end time must be after its start time.');
    }

    const business = await this.prisma.business.findUnique({
      where: { id: businessId },
      select: { id: true },
    });

    if (!business) {
      throw new NotFoundException('Business workspace not found.');
    }

    return this.prisma.campaign.create({
      data: {
        businessId: business.id,
        name,
        channel: data.channel,
        budget: new Prisma.Decimal(data.budget),
        budgetCurrency: currency,
        objective: data.objective?.trim() || undefined,
        startsAt: data.startsAt,
        endsAt: data.endsAt,
        status: CampaignStatus.DRAFT,
      },
    });
  }

  async getCampaignsByBusiness(businessId: string) {
    if (!businessId?.trim()) {
      throw new BadRequestException('A valid business workspace is required.');
    }

    const business = await this.prisma.business.findUnique({
      where: { id: businessId },
      select: { id: true },
    });

    if (!business) {
      throw new NotFoundException('Business workspace not found.');
    }

    return this.prisma.campaign.findMany({
      where: { businessId: business.id },
      orderBy: { createdAt: 'desc' },
    });
  }

  // Require businessId as well as campaignId. This prevents a caller from
  // changing a campaign belonging to another workspace by guessing its ID.
  async updateCampaignStatus(
    businessId: string,
    campaignId: string,
    status: CampaignStatus,
  ) {
    if (!businessId?.trim() || !campaignId?.trim()) {
      throw new BadRequestException('Business and campaign IDs are required.');
    }

    if (!ALLOWED_STATUS_CHANGES.includes(status)) {
      throw new BadRequestException('That campaign status change is not allowed.');
    }

    const result = await this.prisma.campaign.updateMany({
      where: {
        id: campaignId,
        businessId,
      },
      data: { status },
    });

    if (result.count !== 1) {
      throw new NotFoundException('Campaign not found in this business workspace.');
    }

    return this.prisma.campaign.findFirst({
      where: {
        id: campaignId,
        businessId,
      },
    });
  }
}
