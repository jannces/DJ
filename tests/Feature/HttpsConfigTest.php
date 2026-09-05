<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The pieces of the HTTPS setup agree with each other.
 *
 * They did not. `.env` claimed `APP_URL=https://...` and
 * `SESSION_SECURE_COOKIE=true` while `start.bat` ran `php artisan serve` on
 * port 8000 -- plain HTTP, which cannot serve that URL at all -- and then
 * printed advice to turn the secure cookie back off for network access.
 *
 * That combination is worse than either half alone. A cookie marked Secure is
 * not sent by the browser over plain HTTP, so a session either silently fails
 * to stick or only works because something else quietly overrode the setting;
 * and nobody reading the config can tell which.
 *
 * These are cheap file assertions rather than a live TLS handshake, which is
 * the honest limit of what a test suite on another machine can check. What
 * they catch is the drift: one of these files being edited without the others.
 */
class HttpsConfigTest extends TestCase
{
    private const HOST = 'onealicialms.local';

    private function file(string $path): string
    {
        $full = base_path($path);
        $this->assertFileExists($full);

        return file_get_contents($full);
    }

    /** The app's own idea of where it lives is HTTPS. */
    public function test_the_configured_url_is_https(): void
    {
        $this->assertStringContainsString('APP_URL=https://'.self::HOST, $this->file('.env.example'),
            '.env.example does not point at the HTTPS address');
    }

    /**
     * Secure cookies, and a scheme that can actually deliver them.
     *
     * Either both or neither: a Secure cookie over http:// is a session that
     * does not persist, and an https:// site without one hands the session
     * cookie to any plain-HTTP request that can be provoked.
     */
    public function test_the_session_cookie_is_secure_because_the_scheme_is(): void
    {
        $env = $this->file('.env.example');

        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $env);
        $this->assertStringContainsString('APP_URL=https://', $env,
            'the session cookie is marked Secure but the site is not served over HTTPS');
    }

    /**
     * The launcher starts something that can serve TLS.
     *
     * `php artisan serve` cannot. It is the reason the address in .env was
     * unreachable while every part of the configuration insisted on it.
     */
    public function test_the_launcher_starts_apache_not_the_dev_server(): void
    {
        $start = $this->file('start.bat');

        $this->assertStringNotContainsString('artisan serve --host', $start,
            'start.bat still launches the HTTP-only dev server, which cannot answer on https://');
        $this->assertStringContainsString('httpd.exe', $start);
        $this->assertStringContainsString('https://%SITE%', $start);
    }

    /** And the stopper stops it, rather than leaving port 443 held. */
    public function test_the_stopper_stops_apache(): void
    {
        $this->assertStringContainsString('httpd.exe', $this->file('stop.bat'),
            'stop.bat leaves Apache running, so port 443 stays held after shutdown');
    }

    /** Every file that names the host names the same one. */
    public function test_one_hostname_everywhere(): void
    {
        foreach ([
            '.env.example',
            'deploy/apache-vhost.conf',
            'deploy/make-cert.sh',
            'deploy/make-cert.bat',
            'start.bat',
        ] as $path) {
            $this->assertStringContainsString(self::HOST, $this->file($path),
                "{$path} does not name the site's hostname");
            $this->assertStringNotContainsString('lms.alicia.local', $this->file($path),
                "{$path} still carries the old hostname");
        }
    }

    /**
     * The certificate covers the name people type.
     *
     * A certificate whose subjectAltName omits the hostname produces a
     * NAME_MISMATCH the browser will not let anybody past, which looks exactly
     * like a broken server rather than an untrusted one.
     */
    public function test_the_certificate_covers_the_hostname_and_localhost(): void
    {
        foreach (['deploy/make-cert.sh', 'deploy/make-cert.bat'] as $path) {
            $script = $this->file($path);

            $this->assertStringContainsString('subjectAltName', $script,
                "{$path} issues a certificate with no subjectAltName, which modern browsers reject outright");
            $this->assertStringContainsString('DNS:localhost', $script);
        }
    }

    /**
     * No HSTS while the certificate is self-signed.
     *
     * Strict-Transport-Security makes Chrome refuse an untrusted certificate
     * with no way to click past it. Against a self-signed certificate that
     * turns a first-visit warning into a locked door on every office PC.
     */
    public function test_hsts_is_not_enabled_against_a_self_signed_certificate(): void
    {
        $this->assertStringNotContainsString('Strict-Transport-Security',
            preg_replace('/^\s*#.*$/m', '', $this->file('deploy/apache-vhost.conf')),
            'HSTS is set while the certificate is self-signed, which locks users out rather than warning them');
    }

    /**
     * The setup script is safe to run on somebody else's Apache.
     *
     * It edits httpd.conf, httpd-vhosts.conf and the hosts file unattended,
     * which is only defensible if it can be undone and cannot half-apply.
     */
    public function test_the_setup_script_backs_up_and_checks_for_admin(): void
    {
        $setup = $this->file('deploy/setup-https.bat');

        $this->assertStringContainsString('net session', $setup,
            'the script does not check for administrator, so it would fail part-way through');

        foreach (['%CONF%', '%VHOSTS%', '%HOSTSFILE%'] as $target) {
            $this->assertMatchesRegularExpression('/copy \/Y "'.preg_quote($target, '/').'"/', $setup,
                "{$target} is edited with no backup taken first");
        }

        // %DATE% is locale-formatted and can yield a slash, which is illegal in
        // a filename -- every backup would fail, on some machines only.
        $this->assertStringNotContainsString('%DATE:', $setup,
            'the backup timestamp is built from %DATE%, whose format depends on the machine locale');

        // Running it twice must not append the Include or the hosts line again.
        $this->assertStringContainsString('findstr /C:"apache-vhost.local.conf"', $setup);
        $this->assertStringContainsString('findstr /C:"%SITE%" "%HOSTSFILE%"', $setup);
    }

    /** The generated per-machine vhost is not committed either. */
    public function test_the_generated_vhost_is_ignored_by_git(): void
    {
        $this->assertStringContainsString('/deploy/apache-vhost.local.conf', $this->file('.gitignore'),
            'the generated vhost carries one machine\'s paths and subnet and must not be shared');
    }

    /**
     * The private key is not committed.
     *
     * It is generated per installation. A key in the repository is a key on
     * every machine that ever clones it.
     */
    public function test_the_tls_private_key_is_ignored_by_git(): void
    {
        $this->assertStringContainsString('/deploy/certs/', $this->file('.gitignore'),
            'the generated TLS private key is not gitignored');

        $this->assertFalse(is_file(base_path('deploy/certs/lms.key')),
            'a TLS private key is present in the working tree');
    }
}
