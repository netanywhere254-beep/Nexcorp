/**
 * NexISP MikroTik service — NestJS reference implementation.
 *
 * STATUS: Reference code only. The current Nexcorp application direction is
 * Laravel + MySQL, and this NestJS service is not wired into that application.
 * If a separate NestJS network-integration service is approved, move this file
 * into that service, install node-routeros, add module wiring, and test against
 * a lab router before connecting production equipment.
 *
 * SECURITY:
 * - routerConfig must be loaded server-side from encrypted storage/secret manager.
 * - Never accept router credentials directly from a browser/mobile client.
 * - Never log router passwords or PPPoE subscriber passwords.
 * - Restrict RouterOS API access to a private management network/VPN and firewall
 *   allowlist. Do not expose the router API port to the public internet.
 * - Apply tenant authorization and audit logging before calling these methods.
 */
import { Injectable, Logger } from '@nestjs/common';
// @ts-ignore — node-routeros may not provide TypeScript declarations in all versions.
import { RouterOSAPI } from 'node-routeros';

export interface RouterConnectionConfig {
  host: string;
  user: string;
  /** Server-side secret only. Never return this value from an API response. */
  pass: string;
  port?: number;
}

type RouterOsConnection = {
  connect: () => Promise<unknown>;
  write: (command: string, parameters?: string[]) => Promise<any>;
  disconnect: () => Promise<unknown>;
};

@Injectable()
export class MikrotikService {
  private readonly logger = new Logger(MikrotikService.name);

  private validateRouterConfig(config: RouterConnectionConfig): void {
    if (!config?.host?.trim() || !config?.user?.trim() || !config?.pass) {
      throw new Error('Incomplete router connection configuration');
    }

    const port = config.port ?? 8728;
    if (!Number.isInteger(port) || port < 1 || port > 65535) {
      throw new Error('Invalid router API port');
    }
  }

  private validateRequiredValue(value: string, fieldName: string): void {
    if (typeof value !== 'string' || value.trim().length === 0) {
      throw new Error(`${fieldName} is required`);
    }
    if (value.length > 128 || value.includes('\0')) {
      throw new Error(`${fieldName} is invalid`);
    }
  }

  private async withRouter<T>(
    routerConfig: RouterConnectionConfig,
    operationName: string,
    publicError: string,
    operation: (connection: RouterOsConnection) => Promise<T>,
  ): Promise<T> {
    this.validateRouterConfig(routerConfig);

    const connection = new RouterOSAPI({
      host: routerConfig.host,
      user: routerConfig.user,
      password: routerConfig.pass,
      port: routerConfig.port ?? 8728,
    }) as RouterOsConnection;

    let connected = false;

    try {
      await connection.connect();
      connected = true;
      return await operation(connection);
    } catch (error) {
      // Deliberately avoid logging raw provider errors: they may contain
      // subscriber data or other sensitive values.
      const errorType = error instanceof Error ? error.name : 'UnknownError';
      this.logger.error(
        `MikroTik operation "${operationName}" failed for ${routerConfig.host} (${errorType}).`,
      );
      throw new Error(publicError);
    } finally {
      if (connected) {
        try {
          await connection.disconnect();
        } catch {
          this.logger.warn(
            `MikroTik connection cleanup failed for ${routerConfig.host}.`,
          );
        }
      }
    }
  }

  /**
   * Creates a PPPoE secret on the selected MikroTik router.
   * Call only after the caller has authorized the tenant and selected a
   * plan/profile belonging to that same tenant.
   */
  async createPppoeUser(
    routerConfig: RouterConnectionConfig,
    username: string,
    password: string,
    profile: string,
  ): Promise<{ success: true; result: any }> {
    this.validateRequiredValue(username, 'PPPoE username');
    this.validateRequiredValue(password, 'PPPoE password');
    this.validateRequiredValue(profile, 'PPPoE profile');

    return this.withRouter(
      routerConfig,
      'create PPPoE user',
      'MikroTik communication error while creating PPPoE user',
      async (connection) => {
        const result = await connection.write('/ppp/secret/add', [
          `=name=${username}`,
          `=password=${password}`,
          `=profile=${profile}`,
          '=service=pppoe',
        ]);

        return { success: true as const, result };
      },
    );
  }

  /**
   * Removes all active PPP sessions for the supplied username.
   * This disconnects active sessions; it does not delete the PPP secret.
   */
  async disconnectUser(
    routerConfig: RouterConnectionConfig,
    username: string,
  ): Promise<{ success: true; disconnectedSessions: number }> {
    this.validateRequiredValue(username, 'PPPoE username');

    return this.withRouter(
      routerConfig,
      'disconnect PPPoE user',
      'Failed to terminate active PPPoE session',
      async (connection) => {
        const activeSessions = (await connection.write('/ppp/active/print', [
          `?name=${username}`,
        ])) as Array<{ '.id'?: string }>;

        let disconnectedSessions = 0;

        for (const session of activeSessions ?? []) {
          if (!session['.id']) continue;

          await connection.write('/ppp/active/remove', [
            `=.id=${session['.id']}`,
          ]);
          disconnectedSessions += 1;
        }

        return { success: true as const, disconnectedSessions };
      },
    );
  }
}
