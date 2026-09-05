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

            // And it must CHECK, not announce. Both scripts printed
            // "Created ..." unconditionally, so an openssl that had failed --
            // XAMPP's looks for a config at C:\Apache24\conf, which does not
            // exist -- still read as success while dropping the SAN.
            $this->assertStringContainsString('-ext subjectAltName', $script,
                "{$path} says the certificate was created without checking that it carries a SAN");
        }
    }

    /**
     * openssl is pointed at a config file that exists.
     *
     * XAMPP's openssl.exe is built with a default of C:\Apache24\conf\openssl.cnf
     * -- a path no XAMPP install has. Without a config it silently drops
     * `-addext subjectAltName`, and the certificate it writes is one every
     * browser refuses with ERR_CERT_COMMON_NAME_INVALID.
     */
    public function test_the_windows_cert_script_sets_openssl_conf(): void
    {
        $script = $this->file('deploy/make-cert.bat');

        $this->assertStringContainsString('OPENSSL_CONF', $script,
            'openssl is left to find its own config, which on XAMPP means not finding one');
        $this->assertStringContainsString('apache\\conf\\openssl.cnf', $script);
    }

    /**
     * XAMPP's OWN default certificate is checked, because it stops Apache dead.
     *
     * httpd-ssl.conf ships a <VirtualHost _default_:443> for www.example.com
     * using XAMPP's bundled server.crt/server.key, and on at least one install
     * those two are not a pair. mod_ssl treats that as fatal for the whole
     * server rather than for that vhost:
     *
     *   AH02565: Certificate and private key www.example.com:443:0 ... do not match
     *   AH00016: Configuration Failed
     *
     * Apache exits, XAMPP says "shutdown unexpectedly", and `httpd -t` reports
     * Syntax OK -- because the syntax is fine. Two files simply are not a pair.
     * Enabling the Include is what makes it matter, and the Include is needed
     * for its Listen 443, so the setup has to deal with it.
     */
    public function test_setup_repairs_xampps_own_mismatched_certificate(): void
    {
        $setup = $this->file('deploy/setup-https.bat');

        $this->assertStringContainsString('ssl.crt\\server.crt', $setup,
            "setup does not look at XAMPP's own default certificate, which can stop Apache starting");

        // A cert and a key are a pair only if their moduli match; comparing
        // anything else is not a test of whether they belong together.
        $this->assertStringContainsString('-modulus', $setup,
            'the default pair is not compared by modulus, so a mismatch would go unnoticed');

        // And the originals are kept.
        $this->assertMatchesRegularExpression('/copy \/Y "%XCRT%"/', $setup);
        $this->assertMatchesRegularExpression('/copy \/Y "%XKEY%"/', $setup);
    }

    /** An existing but SAN-less certificate is replaced, not trusted. */
    public function test_setup_verifies_an_existing_certificate(): void
    {
        $setup = $this->file('deploy/setup-https.bat');

        $this->assertStringContainsString('-ext subjectAltName', $setup,
            'setup keeps any existing lms.crt without checking the browser would accept it');
    }

    /**
     * No PowerShell PIPELINE inside a backtick FOR block.
     *
     * That is where the IP lookup broke on the first real run. cmd parses the
     * command inside the backticks itself, so a pipe has to be written `^|`
     * and the quoting stops behaving -- the step reported "Could not read this
     * machine's IPv4 address" and fell back to 127.0.0.1 only. Outside a FOR
     * block, a pipe inside double quotes is literal and cmd leaves it alone.
     *
     * The escaped pipe is the tell, not the backtick FOR itself: a single
     * command with no pipe is fine in one, and the timestamp lookup is exactly
     * that.
     */
    public function test_no_escaped_pipe_survives_in_the_setup_script(): void
    {
        $this->assertStringNotContainsString('^|', $this->file('deploy/setup-https.bat'),
            'a pipeline is being run inside a backtick FOR block, which is what silently returned nothing');
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
    }

    /**
     * A commented-out hosts line does not count as a mapping.
     *
     * Searching the file for the hostname matches a comment just as happily
     * as a mapping, and that is how one machine ended up with
     *
     *     #\t127.0.0.1       onealicialms.local
     *     #\t192.168.254.102 onealicialms.local
     *
     * and no working name: both are comments, Windows ignores them, but the
     * search found the hostname, the step reported "Already mapped", and no
     * real entry was ever written. The browser said
     * DNS_PROBE_FINISHED_NXDOMAIN while the file appeared to contain the
     * answer twice.
     *
     * The line has to START with an address. '#' is not in that character
     * class, so a commented line cannot match.
     */
    public function test_a_commented_hosts_line_is_not_mistaken_for_a_mapping(): void
    {
        $setup = $this->file('deploy/setup-https.bat');

        $this->assertStringContainsString('[0-9A-Fa-f:.]+', $setup,
            'the hosts check does not require the line to begin with an address, '
            .'so a commented-out entry reads as a working mapping');

        $this->assertStringNotContainsString('findstr /C:"%SITE%" "%HOSTSFILE%" >nul', $setup,
            'the plain findstr check is back, and it matches comments');
    }

    /**
     * The firewall is opened, and only as far as it needs to be.
     *
     * Without a rule the system works perfectly on the server and is invisible
     * to every other PC in the office -- Windows blocks inbound 443 by default
     * and does it silently, so the other machine simply times out, which reads
     * as "the server is down".
     *
     * Scoped on both axes: private and domain profiles only, so a laptop on a
     * public network is not serving this, and the local subnet only, which is
     * the same boundary the vhost's `Require ip` draws one layer up. Two layers
     * saying the same thing means a mistake in one of them is not an open port.
     */
    public function test_the_firewall_is_opened_narrowly(): void
    {
        $setup = $this->file('deploy/setup-https.bat');

        $this->assertStringContainsString('localport=443', $setup,
            'nothing opens inbound 443, so no other PC on the network can reach the system');
        $this->assertStringContainsString('profile=private,domain', $setup,
            'the firewall rule also applies on public networks');
        $this->assertStringContainsString('remoteip=localsubnet', $setup,
            'the firewall rule is not limited to the local subnet');

        // Adding the same rule twice leaves duplicates in the firewall list.
        $this->assertStringContainsString('firewall show rule name="LGU Alicia LMS (HTTPS)"', $setup,
            'the rule is added without checking whether it is already there');
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
