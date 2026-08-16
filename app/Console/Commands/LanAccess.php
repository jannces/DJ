<?php

namespace App\Console\Commands;

use App\Models\AuthorizedDevice;
use App\Models\SystemSetting;
use Illuminate\Console\Command;

/**
 * Prints the URLs other devices on the LAN should open, and checks the
 * settings that silently break cross-device access (see docs/LAN-Access.md).
 *
 * Every check here maps to a failure that looks like something else:
 * a secure-only session cookie looks like a wrong password, a closed
 * firewall port looks like the server being down, and device enforcement
 * looks like a broken page.
 */
class LanAccess extends Command
{
    protected $signature = 'lms:lan
        {--port=8000 : Port the system is served on (use 80 for Apache)}
        {--https : Check an HTTPS deployment instead of plain HTTP}';

    protected $description = 'Show the LAN URLs for opening this system on another device, and check what would block it.';

    private const PRIVATE_LABEL = 'network adapter';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        $secure = (bool) $this->option('https');
        $scheme = $secure ? 'https' : 'http';

        $this->newLine();
        $this->line('  <options=bold>LGU Alicia LMS — access from another device</>');

        $addresses = $this->detectAddresses();
        $this->printAddresses($addresses, $scheme, $port);
        $this->printStartCommand($port);

        $this->newLine();
        $this->line('  <options=bold>Checks</>');
        $problems = 0;
        $problems += $this->checkSessionCookie($secure);
        $problems += $this->checkAppUrl($addresses, $scheme, $port);
        $problems += $this->checkConfigCache();
        $problems += $this->checkDeviceEnforcement();

        $this->printFirewall($port);

        $this->newLine();
        $this->line('  Full guide: <options=bold>docs/LAN-Access.md</>');
        $this->newLine();

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ---------------------------------------------------------------- output

    private function printAddresses(array $addresses, string $scheme, int $port): void
    {
        $this->newLine();

        if ($addresses === []) {
            $this->line('  <fg=red>Could not detect a private network address on this machine.</>');
            $this->line('  Run <options=bold>ipconfig</> (Windows) or <options=bold>ip -4 addr</> (Linux) and read the IPv4 address yourself.');
            $this->line('  If every address is 169.254.x.x, this machine has no network — check the cable or Wi-Fi.');

            return;
        }

        $this->line('  On the other device — same Wi-Fi or switch — open:');
        $this->newLine();

        $suffix = $this->isDefaultPort($scheme, $port) ? '' : ':'.$port;
        $width = max(array_map(fn ($row) => strlen($row['ip']), $addresses)) + strlen($scheme) + strlen($suffix) + 4;

        foreach ($addresses as $row) {
            $url = "{$scheme}://{$row['ip']}{$suffix}";
            $this->line(sprintf('    <options=bold;fg=green>%s</>%s  <fg=gray>%s</>',
                $url, str_repeat(' ', max(1, $width - strlen($url))), $row['label']));
        }

        if (count($addresses) > 1) {
            $this->newLine();
            $this->line('  <fg=gray>Several adapters are up — use the one on the same network as the other device.</>');
        }
    }

    private function printStartCommand(int $port): void
    {
        $this->newLine();
        $this->line('  Start the server with:');
        $this->newLine();

        if ($port === 80 || $port === 443) {
            $this->line('    <options=bold>Apache (XAMPP Control Panel → Start)</>');
            $this->line('    <fg=gray>Uses deploy/apache-vhost-ip.conf — see docs/LAN-Access.md step 3B.</>');

            return;
        }

        $this->line("    <options=bold>php artisan serve --host=0.0.0.0 --port={$port}</>");
        $this->newLine();
        $this->line('  <fg=gray>Without --host=0.0.0.0 the server listens on 127.0.0.1 only and</>');
        $this->line('  <fg=gray>no other machine can reach it, however correct everything else is.</>');
    }

    private function printFirewall(int $port): void
    {
        $this->newLine();
        $this->line('  <options=bold>Firewall</> <fg=gray>(one time, cannot be checked from here)</>');
        $this->newLine();

        if (PHP_OS_FAMILY === 'Windows') {
            $this->line('  Windows blocks inbound connections by default, which looks exactly like');
            $this->line('  the server being down. In an <options=bold>Administrator</> Command Prompt, run:');
            $this->newLine();
            $this->line("    <options=bold>netsh advfirewall firewall add rule name=\"LMS HTTP {$port}\" dir=in action=allow protocol=TCP localport={$port}</>");
            $this->newLine();
            $this->line('  <fg=gray>Also set the network profile to Private, not Public.</>');

            return;
        }

        $this->line("    <options=bold>sudo ufw allow {$port}/tcp</>   <fg=gray># or the equivalent for your firewall</>");
    }

    // ---------------------------------------------------------------- checks

    private function checkSessionCookie(bool $secure): int
    {
        $cookieIsSecureOnly = (bool) config('session.secure');

        if ($secure) {
            return $this->ok('Session cookie is HTTPS-only, matching the HTTPS deployment.', ! $cookieIsSecureOnly
                ? 'SESSION_SECURE_COOKIE is false — set it to true for an HTTPS deployment.'
                : null);
        }

        if ($cookieIsSecureOnly) {
            $this->fail('SESSION_SECURE_COOKIE=true, but you are serving over plain http://');
            $this->detail('Browsers refuse to store a Secure cookie on an insecure origin, so the');
            $this->detail('session and CSRF token are discarded on every request. The login page');
            $this->detail('loads on the other device, but signing in bounces straight back to it');
            $this->detail('(or returns 419 Page Expired). No error names the cookie.');
            $this->detail('Fix: set SESSION_SECURE_COOKIE=false in .env, then php artisan config:clear');

            return 1;
        }

        return $this->ok('Session cookie works over plain HTTP.');
    }

    private function checkAppUrl(array $addresses, string $scheme, int $port): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $host = parse_url($appUrl, PHP_URL_HOST);

        if ($host === null || $host === false) {
            $this->warn2("APP_URL is not a valid URL: {$appUrl}");

            return 1;
        }

        $ips = array_column($addresses, 'ip');

        if (in_array($host, $ips, true)) {
            return $this->ok("APP_URL points at this machine ({$appUrl}).");
        }

        if (! filter_var($host, FILTER_VALIDATE_IP) && $scheme === 'https') {
            return $this->ok("APP_URL uses the hostname {$host} — make sure the other device resolves it.");
        }

        // With no detected address there is nothing to compare against; saying
        // a correct APP_URL is wrong would be worse than staying quiet.
        if ($ips === [] && filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->ok("APP_URL is {$appUrl} — could not confirm it against this machine's addresses.");
        }

        $suggested = $ips === [] ? 'YOUR-IP' : $ips[0];
        $suffix = $this->isDefaultPort($scheme, $port) ? '' : ':'.$port;

        $this->warn2("APP_URL is {$appUrl}, which is not this machine's LAN address.");
        $this->detail('Absolute links and asset URLs are built from it, so pages can load');
        $this->detail('unstyled or redirect the other device back to a host it cannot reach.');
        $this->detail("Fix: APP_URL={$scheme}://{$suggested}{$suffix} in .env, then php artisan config:clear");

        return 1;
    }

    private function checkConfigCache(): int
    {
        if (! app()->configurationIsCached()) {
            return $this->ok('Config is not cached — .env edits take effect immediately.');
        }

        $this->warn2('Config is cached: edits to .env are ignored until you rebuild it.');
        $this->detail('Fix: php artisan config:clear   (then config:cache again for production)');

        return 1;
    }

    private function checkDeviceEnforcement(): int
    {
        try {
            $enforcing = (bool) SystemSetting::get('security.device_enforcement', false);
        } catch (\Throwable $e) {
            $this->warn2('Could not read system settings — is MySQL running in XAMPP?');
            $this->detail($e->getMessage());

            return 1;
        }

        if (! $enforcing) {
            return $this->ok('Device enforcement is OFF — any LAN address may connect.', 'Turn it back ON once the office workstations are registered.');
        }

        $devices = AuthorizedDevice::active()->pluck('hostname', 'ip_address');
        $remote = $devices->keys()->reject(fn ($ip) => in_array($ip, ['127.0.0.1', '::1'], true));

        if ($remote->isEmpty()) {
            $this->fail('Device enforcement is ON and only loopback is authorized.');
            $this->detail('Every other device gets a 403 "Unauthorized device" page and is');
            $this->detail('written to the intrusion log.');
            $this->detail('Fix: Administration → Authorized Devices, or:');
            $this->detail('  php artisan lms:device:add 192.168.1.25 "HR Laptop"');

            return 1;
        }

        $this->ok("Device enforcement is ON with {$remote->count()} workstation(s) registered:");
        foreach ($devices as $ip => $hostname) {
            $this->detail(sprintf('%-16s %s', $ip, $hostname));
        }
        $this->detail('Any address not listed gets a 403. The allow-list is cached for 60s.');

        return 0;
    }

    // --------------------------------------------------------- check helpers

    private function ok(string $message, ?string $note = null): int
    {
        $this->line("  <fg=green>[ ok ]</> {$message}");
        if ($note !== null) {
            $this->detail($note);
        }

        return 0;
    }

    private function warn2(string $message): void
    {
        $this->line("  <fg=yellow>[warn]</> {$message}");
    }

    private function fail(string $message): void
    {
        $this->line("  <fg=red>[FAIL]</> {$message}");
    }

    private function detail(string $message): void
    {
        $this->line("         <fg=gray>{$message}</>");
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    // ------------------------------------------------------- address probing

    /** @return list<array{ip: string, label: string}> */
    private function detectAddresses(): array
    {
        $found = [];

        foreach ([$this->fromPhp(), $this->fromShell(), $this->fromSocket()] as $source) {
            foreach ($source as $ip => $label) {
                if ($this->isPrivateIpv4($ip) && ! isset($found[$ip])) {
                    $found[$ip] = $label;
                }
            }
        }

        ksort($found);

        return array_map(fn ($ip, $label) => ['ip' => $ip, 'label' => $label],
            array_keys($found), array_values($found));
    }

    /** net_get_interfaces() is the cleanest source, but is not available on Windows. */
    private function fromPhp(): array
    {
        if (! function_exists('net_get_interfaces')) {
            return [];
        }

        $out = [];
        foreach (@net_get_interfaces() ?: [] as $name => $interface) {
            foreach ($interface['unicast'] ?? [] as $unicast) {
                if (($unicast['family'] ?? null) === AF_INET && isset($unicast['address'])) {
                    $out[$unicast['address']] = $name;
                }
            }
        }

        return $out;
    }

    private function fromShell(): array
    {
        if (! function_exists('shell_exec') || in_array('shell_exec', $this->disabledFunctions(), true)) {
            return [];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return $this->parseIpconfig((string) @shell_exec('ipconfig'));
        }

        $output = (string) @shell_exec('ip -4 -o addr show 2>/dev/null');
        if (trim($output) !== '') {
            return $this->parseIpOutput($output);
        }

        return $this->parseIfconfig((string) @shell_exec('ifconfig 2>/dev/null'));
    }

    /**
     * Localised Windows still writes the token "IPv4" on the address line
     * ("Dirección IPv4", "IPv4-Adresse"), while the subnet-mask and
     * default-gateway lines never contain it — so that token, not the
     * English label, is what separates an address from a gateway.
     */
    private function parseIpconfig(string $output): array
    {
        $addresses = [];
        $label = self::PRIVATE_LABEL;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if ($line !== '' && ! preg_match('/^\s/', $line) && str_contains($line, ':')) {
                $label = trim(rtrim(trim($line), ':'));
                if (preg_match('/adapter\s+(.+)$/i', $label, $m)) {
                    $label = trim($m[1]);
                }

                continue;
            }

            if (str_contains($line, 'IPv4') && preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $addresses[$m[1]] = $label;
            }
        }

        return $addresses;
    }

    private function parseIpOutput(string $output): array
    {
        $addresses = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $addresses[$m[2]] = $m[1];
            }
        }

        return $addresses;
    }

    private function parseIfconfig(string $output): array
    {
        $addresses = [];
        $label = self::PRIVATE_LABEL;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^(\S+):/', $line, $m)) {
                $label = $m[1];

                continue;
            }

            if (preg_match('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3})/', $line, $m)) {
                $addresses[$m[1]] = $label;
            }
        }

        return $addresses;
    }

    /**
     * Last resort: ask the routing table which local address it would use.
     * A UDP "connection" sends no packet, so this works on an offline LAN.
     */
    private function fromSocket(): array
    {
        $socket = @stream_socket_client('udp://192.0.2.1:53', $errno, $errstr, 1);
        if ($socket === false) {
            return [];
        }

        $name = @stream_socket_get_name($socket, false);
        fclose($socket);

        if (! is_string($name) || ! str_contains($name, ':')) {
            return [];
        }

        return [substr($name, 0, strrpos($name, ':')) => self::PRIVATE_LABEL];
    }

    private function disabledFunctions(): array
    {
        return array_map('trim', explode(',', (string) ini_get('disable_functions')));
    }

    /** Private (RFC1918) and not loopback, APIPA or otherwise reserved. */
    private function isPrivateIpv4(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE) === false;
        $isReserved = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) === false;

        return $isPrivate && ! $isReserved;
    }
}
