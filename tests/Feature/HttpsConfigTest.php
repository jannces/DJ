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
    private const HOST = 'onealicialms.lan';

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
        foreach (['deploy/setup-https.bat', 'deploy/connect-client.bat'] as $path) {
            $this->assertStringNotContainsString('^|', $this->file($path),
                "{$path} runs a pipeline inside a backtick FOR block, which is what silently returned nothing");
        }
    }

    /**
     * The client half of the setup is a script, not a paragraph.
     *
     * The server can be perfectly configured and still be unreachable from
     * every other desk, because two things have to happen on each client PC:
     * the name has to resolve to the server, and the certificate has to be
     * trusted. Left as written instructions, both are done by hand in a
     * protected system file by whoever is nearest, which is how the server
     * itself ended up with the hostname in its hosts file twice, commented
     * out both times, and no working mapping.
     */
    public function test_there_is_a_client_setup_script(): void
    {
        $client = $this->file('deploy/connect-client.bat');

        $this->assertStringContainsString('%HOSTSFILE%', $client,
            'the client script does not write the hosts entry');
        $this->assertStringContainsString('trust-cert.bat', $client,
            'the client script does not trust the certificate, so every user meets a warning page');
        $this->assertStringContainsString('net session', $client,
            'the client script does not check for administrator, and both its steps need it');
        $this->assertMatchesRegularExpression('/copy \/Y "%HOSTSFILE%"/', $client,
            'the hosts file is edited with no backup taken first');
        $this->assertStringContainsString('ipconfig /flushdns', $client,
            'the resolver cache is not flushed, so a name that now works can still report NXDOMAIN');

        // trust-cert.bat has to be callable as a step rather than a
        // conversation, or the client script stops dead on its pause.
        $this->assertStringContainsString('nopause', $this->file('deploy/trust-cert.bat'),
            'trust-cert.bat always pauses, so it cannot be called from another script');
    }

    /**
     * A client is never pointed at its own loopback.
     *
     * 127.0.0.1 is the correct mapping on the server and the wrong one
     * everywhere else, and the natural way to set up the second PC is to copy
     * the line off the first. The result is a name that resolves to the PC
     * asking, which presents as a refused connection rather than as a
     * misconfiguration.
     */
    public function test_the_client_script_refuses_the_loopback_address(): void
    {
        $client = $this->file('deploy/connect-client.bat');

        $this->assertStringContainsString('findstr /B /C:"127."', $client,
            'the client script would happily map the site to 127.0.0.1, which on a client means itself');
    }

    /**
     * A stale mapping is corrected, not left beside a new one.
     *
     * The server's address is handed out by DHCP and will change. A client
     * that already has an entry would otherwise keep asking the old address
     * forever -- and appending a second line would not help, because the
     * resolver takes the first match.
     */
    public function test_the_client_script_corrects_a_stale_mapping(): void
    {
        $client = $this->file('deploy/connect-client.bat');

        $this->assertStringContainsString('%CURIP%', $client,
            'the client script does not read the existing mapping, so it cannot tell a stale one from a correct one');
        $this->assertStringContainsString('[0-9A-Fa-f:.]+', $client,
            'the hosts check does not require the line to begin with an address, '
            .'so a commented-out entry reads as a working mapping');
    }

    /**
     * The hostname does not end in .local, and nothing still points at it.
     *
     * `.local` is reserved for mDNS. iOS and macOS resolve those names through
     * Bonjour and never ask the router, so a DNS record for a .local name
     * cannot reach a phone however correctly it is entered -- and Windows can
     * route .local to mDNS too, which is what produced a hosts file that read
     * correctly beside a browser reporting DNS_PROBE_FINISHED_NXDOMAIN.
     *
     * The suffix is the whole point of the assertion: any other name would be
     * fine, and `.local` would silently undo the phone support.
     */
    public function test_the_hostname_is_not_reserved_for_mdns(): void
    {
        $this->assertStringEndsNotWith('.local', self::HOST);

        // Files with no business naming it at all.
        foreach ([
            '.env.example',
            'deploy/apache-vhost.conf',
            'deploy/make-cert.sh',
            'deploy/make-cert.bat',
            'deploy/trust-cert.bat',
            'start.bat',
        ] as $path) {
            $this->assertStringNotContainsString('onealicialms.local', $this->file($path),
                "{$path} still carries the old .local name, which no phone can resolve");
        }

        // The two setup scripts DO name it -- they remove it. What they must
        // not do is serve it.
        foreach (['deploy/setup-https.bat', 'deploy/connect-client.bat'] as $path) {
            $script = $this->file($path);

            $this->assertStringNotContainsString('set SITE=onealicialms.local', $script,
                "{$path} still serves the old .local name");
            $this->assertStringNotContainsString('https://onealicialms.local', $script,
                "{$path} still sends people to the old .local address");
        }
    }

    /**
     * Both setup scripts clean up the name they replaced.
     *
     * A stale hosts line and a stale trusted certificate each keep working on
     * their own, so a PC configured before the rename would go on using the
     * old name while everyone assumed the system had moved.
     */
    public function test_the_setup_scripts_undo_the_old_local_name(): void
    {
        foreach (['deploy/setup-https.bat', 'deploy/connect-client.bat'] as $path) {
            $script = $this->file($path);

            // Either spelling: setup-https.bat holds the old name in
            // %OLDSITE%, connect-client.bat writes it inline.
            $this->assertMatchesRegularExpression(
                '/certutil -delstore Root "(onealicialms\.local|%OLDSITE%)"/', $script,
                "{$path} leaves the old certificate trusted");
            $this->assertStringContainsString("-notmatch 'onealicialms\\.local'", $script,
                "{$path} leaves the old hosts lines in place");
        }
    }

    /**
     * One timestamped backup is not overwritten by a second.
     *
     * Both the cleanup step and the hosts step write to
     * hosts.backup-<stamp>, and the stamp is fixed for the run. Copying twice
     * would replace the untouched original with the already-edited version and
     * leave a backup that restores nothing -- which is worse than no backup,
     * because it looks like one.
     */
    public function test_the_hosts_backup_is_not_clobbered_by_the_second_write(): void
    {
        foreach (['deploy/setup-https.bat', 'deploy/connect-client.bat'] as $path) {
            $this->assertStringContainsString(
                'if not exist "%HOSTSFILE%.backup-%STAMP%" copy /Y "%HOSTSFILE%"',
                $this->file($path),
                "{$path} takes a second backup over the first, destroying the original");
        }
    }

    /**
     * The certificate is one a phone will accept.
     *
     * Two extensions, both stated explicitly rather than inherited:
     *
     * - serverAuth, required by Apple on TLS server certificates since
     *   iOS 13. Without it an iPhone can install and trust the certificate
     *   and the connection still fails, with every step appearing to work.
     * - CA:TRUE, without which Android's "Install a certificate -> CA
     *   certificate" screen refuses the file outright. It was already being
     *   set, but only as a side effect of the v3_ca section in whichever
     *   openssl.cnf happened to be found.
     */
    public function test_the_certificate_is_acceptable_to_phones(): void
    {
        foreach (['deploy/make-cert.sh', 'deploy/make-cert.bat'] as $path) {
            $script = $this->file($path);

            $this->assertStringContainsString('extendedKeyUsage=serverAuth', $script,
                "{$path} issues a certificate with no serverAuth EKU, which iOS rejects");
            $this->assertStringContainsString('basicConstraints=critical,CA:TRUE', $script,
                "{$path} leaves CA:TRUE to the openssl config, and Android needs it to install the file at all");
            $this->assertStringContainsString('-ext extendedKeyUsage', $script,
                "{$path} does not check the EKU it asked for actually landed");
        }
    }

    /**
     * The server's own address is in the certificate.
     *
     * A phone has no hosts file. Where the router cannot hold a DNS record the
     * only way in is https://<server ip>, and a certificate that lists only
     * the name fails that with NAME_MISMATCH.
     */
    public function test_the_certificate_covers_the_servers_own_address(): void
    {
        $this->assertStringContainsString('IP:%SERVERIP%', $this->file('deploy/make-cert.bat'),
            'the certificate cannot cover the server IP, so a phone browsing by address gets NAME_MISMATCH');

        $this->assertStringContainsString('nopause %HOSTIP%', $this->file('deploy/setup-https.bat'),
            'setup detects the server IP and then does not pass it to the certificate');
    }

    /** The private half of the certificate is never suggested for copying. */
    public function test_nothing_tells_anyone_to_copy_the_private_key(): void
    {
        foreach (['deploy/connect-client.bat', 'deploy/trust-cert.bat', 'deploy/setup-https.bat'] as $path) {
            $this->assertStringContainsString('NEVER copy lms.key', $this->file($path),
                "{$path} discusses moving certificate files between PCs without warning off the private key");
        }
    }

    /**
     * The vhost does not add HSTS on top of the application's.
     *
     * Narrower than it used to claim, and the difference matters. This asserts
     * only that the Apache config stays out of it --
     * app/Http/Middleware/SecurityHeaders.php DOES send
     * Strict-Transport-Security on every secure request, so the system is not
     * HSTS-free and the old name of this test ("hsts is not enabled") said
     * otherwise.
     *
     * Two layers both setting it would be worse than one: the vhost's copy
     * would survive any change to the middleware, including deliberately
     * turning it off, and nobody debugging a locked-out browser would think to
     * look in the Apache config.
     */
    public function test_the_vhost_does_not_add_hsts_on_top_of_the_application(): void
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
     *     #\t127.0.0.1       onealicialms.lan
     *     #\t192.168.254.102 onealicialms.lan
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
