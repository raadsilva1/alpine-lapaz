#!/usr/bin/env php
<?php
declare(strict_types=1);

final class ExitCode
{
    public const OK = 0;
    public const USAGE = 2;
    public const UNSUPPORTED = 3;
    public const PRIVILEGE = 4;
    public const PREFLIGHT = 5;
    public const NETWORK = 6;
    public const PACKAGE = 7;
    public const FILESYSTEM = 8;
    public const VALIDATION = 9;
    public const PARTIAL = 10;
    public const ROLLBACK_FAILED = 11;
    public const INTERNAL = 99;
}

final class Ansi
{
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const CYAN = "\033[36m";
}

final class CliException extends RuntimeException
{
    public function __construct(string $message, private readonly int $exitCode = ExitCode::INTERNAL)
    {
        parent::__construct($message);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }
}

final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $command
    ) {}
}

final class Logger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $dir = dirname($logFile);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new CliException("Unable to create log directory: {$dir}", ExitCode::FILESYSTEM);
        }
    }

    public function log(string $level, string $message): void
    {
        $line = sprintf("[%s] [%s] %s\n", date('c'), strtoupper($level), $message);
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function info(string $message): void
    {
        $this->console('INFO', $message, Ansi::BLUE);
    }

    public function warn(string $message): void
    {
        $this->console('WARN', $message, Ansi::YELLOW);
    }

    public function success(string $message): void
    {
        $this->console('OK', $message, Ansi::GREEN);
    }

    public function error(string $message): void
    {
        $this->console('ERROR', $message, Ansi::RED);
    }

    private function console(string $level, string $message, string $color): void
    {
        $this->log($level, $message);
        fwrite(STDOUT, sprintf("%s[%s]%s %s\n", $color, $level, Ansi::RESET, $message));
    }

    public function path(): string
    {
        return $this->logFile;
    }
}

final class ProgressRenderer
{
    private int $current = 0;

    public function __construct(private readonly int $total, private readonly Logger $logger) {}

    public function phase(string $title): void
    {
        $this->current++;
        $prefix = sprintf("%s[%d/%d]%s", Ansi::BOLD, $this->current, $this->total, Ansi::RESET);
        $this->logger->info("{$prefix} {$title}");
    }
}

final class CommandRunner
{
    public function __construct(private readonly Logger $logger) {}

    public function run(string $command, bool $mustSucceed = true): CommandResult
    {
        $this->logger->log('DEBUG', "Executing command: {$command}");

        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/sh', '-c', $command], $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new CliException("Failed to start command: {$command}", ExitCode::INTERNAL);
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $result = new CommandResult($exitCode, trim($stdout), trim($stderr), $command);

        if ($mustSucceed && $exitCode !== 0) {
            $message = "Command failed [{$exitCode}]: {$command}";
            if ($stderr !== '') {
                $message .= " | stderr: {$stderr}";
            }
            throw new CliException($message, ExitCode::INTERNAL);
        }

        return $result;
    }

    public function exists(string $binary): bool
    {
        $result = $this->run("command -v " . escapeshellarg($binary) . " >/dev/null 2>&1", false);
        return $result->exitCode === 0;
    }
}

final class BackupManager
{
    private array $backups = [];

    public function __construct(private readonly string $transactionDir, private readonly Logger $logger)
    {
        if (!is_dir($this->transactionDir) && !@mkdir($this->transactionDir, 0700, true) && !is_dir($this->transactionDir)) {
            throw new CliException("Unable to create transaction directory: {$this->transactionDir}", ExitCode::FILESYSTEM);
        }
    }

    public function backupIfExists(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (isset($this->backups[$path])) {
            return;
        }

        $safeName = ltrim(str_replace('/', '__', $path), '_');
        $backupPath = $this->transactionDir . '/backup-' . $safeName;

        if (is_link($path)) {
            $target = readlink($path);
            if ($target === false) {
                throw new CliException("Unable to read symlink for backup: {$path}", ExitCode::FILESYSTEM);
            }
            file_put_contents($backupPath . '.symlink', $target);
            $this->backups[$path] = $backupPath . '.symlink';
        } else {
            if (!copy($path, $backupPath)) {
                throw new CliException("Unable to backup file: {$path}", ExitCode::FILESYSTEM);
            }
            @chmod($backupPath, fileperms($path) & 0777);
            $this->backups[$path] = $backupPath;
        }

        $this->logger->info("Backed up: {$path}");
    }

    public function restoreAll(): void
    {
        foreach ($this->backups as $original => $backup) {
            if (str_ends_with($backup, '.symlink')) {
                $target = trim((string) file_get_contents($backup));
                @unlink($original);
                if (!symlink($target, $original)) {
                    throw new CliException("Failed to restore symlink: {$original}", ExitCode::ROLLBACK_FAILED);
                }
            } else {
                if (!copy($backup, $original)) {
                    throw new CliException("Failed to restore file: {$original}", ExitCode::ROLLBACK_FAILED);
                }
            }
            $this->logger->warn("Rolled back: {$original}");
        }
    }

    public function list(): array
    {
        return $this->backups;
    }

    public function dir(): string
    {
        return $this->transactionDir;
    }
}

final class FileManager
{
    public function __construct(
        private readonly Logger $logger,
        private readonly BackupManager $backupManager
    ) {}

    public function ensureDir(string $path, int $mode = 0755, ?int $uid = null, ?int $gid = null): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new CliException("Failed to create directory: {$path}", ExitCode::FILESYSTEM);
        }
        @chmod($path, $mode);
        if ($uid !== null && $gid !== null) {
            @chown($path, $uid);
            @chgrp($path, $gid);
        }
    }

    public function writeAtomic(
        string $path,
        string $content,
        int $mode = 0644,
        ?int $uid = null,
        ?int $gid = null
    ): bool {
        $dir = dirname($path);
        $this->ensureDir($dir);

        $current = file_exists($path) ? (string) file_get_contents($path) : null;
        if ($current !== null && hash('sha256', $current) === hash('sha256', $content)) {
            $this->logger->info("Unchanged, skipped write: {$path}");
            return false;
        }

        $this->backupManager->backupIfExists($path);

        $temp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temp, $content) === false) {
            throw new CliException("Failed to stage file: {$temp}", ExitCode::FILESYSTEM);
        }

        @chmod($temp, $mode);
        if ($uid !== null) {
            @chown($temp, $uid);
        }
        if ($gid !== null) {
            @chgrp($temp, $gid);
        }

        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new CliException("Failed atomic replace for: {$path}", ExitCode::FILESYSTEM);
        }

        $this->logger->success("Wrote file: {$path}");
        return true;
    }
}

final class UserTarget
{
    public function __construct(
        public readonly string $username,
        public readonly int $uid,
        public readonly int $gid,
        public readonly string $home,
        public readonly string $shell
    ) {}
}

final class EnvironmentState
{
    public function __construct(
        public readonly bool $isAlpine,
        public readonly bool $isRoot,
        public readonly bool $apkAvailable,
        public readonly bool $networkOk,
        public readonly bool $reposReadable,
        public readonly bool $xorgInstalled,
        public readonly bool $herbstInstalled,
        public readonly bool $rofiInstalled,
        public readonly array $candidateUsers
    ) {}
}

final class EnvironmentDetector
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Logger $logger
    ) {}

    public function detect(?string $requestedUser): array
    {
        $isAlpine = file_exists('/etc/alpine-release');
        $isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim((string) shell_exec('id -u')) === '0';
        $apkAvailable = $this->runner->exists('apk');
        $reposReadable = is_readable('/etc/apk/repositories');
        $networkOk = $this->checkNetwork();

        $xorgInstalled = $this->pkgInstalled('xorg-server') || $this->pkgInstalled('xinit');
        $herbstInstalled = $this->pkgInstalled('herbstluftwm');
        $rofiInstalled = $this->pkgInstalled('rofi');

        $candidates = $this->detectUsers();
        $target = $this->selectUser($requestedUser, $candidates);

        return [
            new EnvironmentState(
                $isAlpine,
                $isRoot,
                $apkAvailable,
                $networkOk,
                $reposReadable,
                $xorgInstalled,
                $herbstInstalled,
                $rofiInstalled,
                $candidates
            ),
            $target
        ];
    }

    private function checkNetwork(): bool
    {
        $tests = [
            'ping -c 1 -W 2 dl-cdn.alpinelinux.org >/dev/null 2>&1',
            'wget -q --spider --timeout=5 https://dl-cdn.alpinelinux.org >/dev/null 2>&1',
        ];

        foreach ($tests as $test) {
            $result = $this->runner->run($test, false);
            if ($result->exitCode === 0) {
                return true;
            }
        }
        return false;
    }

    private function pkgInstalled(string $name): bool
    {
        $result = $this->runner->run('apk info -e ' . escapeshellarg($name), false);
        return $result->exitCode === 0;
    }

    /**
     * @return UserTarget[]
     */
    private function detectUsers(): array
    {
        $users = [];
        foreach (file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 7) {
                continue;
            }

            [$name, , $uid, $gid, , $home, $shell] = $parts;
            $uid = (int) $uid;
            $gid = (int) $gid;

            if ($uid < 1000 || $name === 'nobody') {
                continue;
            }
            if (!is_dir($home)) {
                continue;
            }
            if (str_contains($shell, 'nologin') || str_contains($shell, 'false')) {
                continue;
            }

            $users[] = new UserTarget($name, $uid, $gid, $home, $shell);
        }

        usort($users, fn(UserTarget $a, UserTarget $b) => $a->uid <=> $b->uid);
        return $users;
    }

    /**
     * @param UserTarget[] $users
     */
    private function selectUser(?string $requestedUser, array $users): UserTarget
    {
        if ($requestedUser !== null) {
            foreach ($users as $user) {
                if ($user->username === $requestedUser) {
                    return $user;
                }
            }
            throw new CliException("Requested target user not found or not suitable: {$requestedUser}", ExitCode::PREFLIGHT);
        }

        if (count($users) === 1) {
            return $users[0];
        }

        $sudoUser = getenv('SUDO_USER');
        if ($sudoUser !== false && $sudoUser !== 'root') {
            foreach ($users as $user) {
                if ($user->username === $sudoUser) {
                    return $user;
                }
            }
        }

        if (count($users) > 1) {
            $names = implode(', ', array_map(fn(UserTarget $u) => $u->username, $users));
            throw new CliException(
                "Multiple desktop user candidates found ({$names}). Re-run with --user=<name>.",
                ExitCode::PREFLIGHT
            );
        }

        throw new CliException("No suitable non-root desktop user detected.", ExitCode::PREFLIGHT);
    }
}

final class PackageManager
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly Logger $logger
    ) {}

    public function refreshIndexes(): void
    {
        $this->runner->run('apk update');
        $this->logger->success('Package indexes refreshed');
    }

    /**
     * @param string[] $packages
     * @return array{installable: string[], missing: string[], alreadyInstalled: string[]}
     */
    public function resolvePackages(array $packages): array
    {
        $installable = [];
        $missing = [];
        $alreadyInstalled = [];

        foreach ($packages as $pkg) {
            if ($this->isInstalled($pkg)) {
                $alreadyInstalled[] = $pkg;
                continue;
            }

            if ($this->existsInRepos($pkg)) {
                $installable[] = $pkg;
            } else {
                $missing[] = $pkg;
            }
        }

        return [
            'installable' => $installable,
            'missing' => $missing,
            'alreadyInstalled' => $alreadyInstalled,
        ];
    }

    /**
     * @param string[] $packages
     */
    public function install(array $packages): void
    {
        if ($packages === []) {
            $this->logger->info('No packages needed for installation');
            return;
        }

        $cmd = 'apk add --no-interactive ' . implode(' ', array_map('escapeshellarg', $packages));
        $this->runner->run($cmd);
        $this->logger->success('Package installation phase completed');
    }

    private function isInstalled(string $pkg): bool
    {
        return $this->runner->run('apk info -e ' . escapeshellarg($pkg), false)->exitCode === 0;
    }

    private function existsInRepos(string $pkg): bool
    {
        $cmd = 'apk search -x ' . escapeshellarg($pkg);
        $result = $this->runner->run($cmd, false);
        if ($result->exitCode !== 0 || $result->stdout === '') {
            return false;
        }

        $lines = preg_split('/\R+/', $result->stdout) ?: [];
        foreach ($lines as $line) {
            if (trim($line) === $pkg) {
                return true;
            }
        }
        return false;
    }
}

final class UXValidationReport
{
    /**
     * @param string[] $passes
     * @param string[] $warnings
     * @param string[] $issues
     */
    public function __construct(
        public readonly array $passes,
        public readonly array $warnings,
        public readonly array $issues
    ) {}

    public function isAcceptable(): bool
    {
        return $this->issues === [];
    }
}

final class UXValidator
{
    public function validate(array $generated): UXValidationReport
    {
        $passes = [];
        $warnings = [];
        $issues = [];

        $fontSize = $generated['font_size'] ?? 11;
        if ($fontSize >= 10 && $fontSize <= 14) {
            $passes[] = "Default font size {$fontSize}pt is readable";
        } else {
            $issues[] = "Default font size {$fontSize}pt is outside conservative readable range";
        }

        $fg = $generated['foreground'] ?? '#e5e7eb';
        $bg = $generated['background'] ?? '#1f2937';
        $ratio = $this->contrastRatio($fg, $bg);
        if ($ratio >= 7.0) {
            $passes[] = sprintf('Terminal/launcher contrast ratio %.2f is strong', $ratio);
        } elseif ($ratio >= 4.5) {
            $warnings[] = sprintf('Contrast ratio %.2f is acceptable but not excellent', $ratio);
        } else {
            $issues[] = sprintf('Contrast ratio %.2f is too weak', $ratio);
        }

        if (($generated['rofi_shortcut'] ?? '') === 'Mod4-d') {
            $passes[] = 'Launcher shortcut is practical and discoverable';
        } else {
            $warnings[] = 'Launcher shortcut differs from expected practical default';
        }

        if (($generated['terminal_shortcut'] ?? '') === 'Mod4-Return') {
            $passes[] = 'Terminal shortcut is practical and discoverable';
        } else {
            $warnings[] = 'Terminal shortcut differs from expected practical default';
        }

        if (($generated['notification_timeout'] ?? 0) >= 5000) {
            $passes[] = 'Notification timeout is comfortable for normal reading';
        } else {
            $warnings[] = 'Notification timeout may be too short';
        }

        if (($generated['session_components'] ?? []) === []) {
            $issues[] = 'Session startup components list is empty';
        } else {
            $passes[] = 'Session startup flow includes expected desktop helpers';
        }

        if (($generated['theme_noise'] ?? 'low') === 'low') {
            $passes[] = 'Theme profile remains visually restrained';
        } else {
            $issues[] = 'Theme profile is visually too noisy';
        }

        if (($generated['keybindings_documented'] ?? false) === true) {
            $passes[] = 'Keybindings documentation is present';
        } else {
            $issues[] = 'Keybindings documentation is missing';
        }

        if (($generated['immediate_usability'] ?? false) === true) {
            $passes[] = 'Environment should be usable without immediate manual edits';
        } else {
            $issues[] = 'Environment may require immediate manual repair';
        }

        return new UXValidationReport($passes, $warnings, $issues);
    }

    private function contrastRatio(string $fgHex, string $bgHex): float
    {
        $fg = $this->relativeLuminance($fgHex);
        $bg = $this->relativeLuminance($bgHex);
        $lighter = max($fg, $bg);
        $darker = min($fg, $bg);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $f = function (float $c): float {
            $c /= 255.0;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [229, 231, 235];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

final class Report
{
    public array $completed = [];
    public array $skipped = [];
    public array $issues = [];
    public array $notes = [];

    public function addCompleted(string $item): void { $this->completed[] = $item; }
    public function addSkipped(string $item): void { $this->skipped[] = $item; }
    public function addIssue(string $item): void { $this->issues[] = $item; }
    public function addNote(string $item): void { $this->notes[] = $item; }
}

final class Provisioner
{
    private Report $report;

    public function __construct(
        private readonly Logger $logger,
        private readonly ProgressRenderer $progress,
        private readonly CommandRunner $runner,
        private readonly EnvironmentDetector $detector,
        private readonly PackageManager $packageManager,
        private readonly FileManager $files,
        private readonly BackupManager $backupManager,
        private readonly UXValidator $validator
    ) {
        $this->report = new Report();
    }

    public function run(?string $requestedUser): int
    {
        [$state, $user] = $this->detector->detect($requestedUser);

        $this->progress->phase('Preflight validation');
        $this->validatePreflight($state, $user);

        $this->progress->phase('Package repository refresh');
        $this->packageManager->refreshIndexes();
        $this->report->addCompleted('Refreshed apk indexes');

        $this->progress->phase('Package planning');
        [$installable, $missing, $alreadyInstalled] = $this->planPackages();

        foreach ($alreadyInstalled as $pkg) {
            $this->report->addSkipped("Already installed: {$pkg}");
        }
        foreach ($missing as $pkg) {
            $this->report->addIssue("Package not available in configured repositories: {$pkg}");
        }

        $this->progress->phase('Package installation');
        $this->packageManager->install($installable);
        foreach ($installable as $pkg) {
            $this->report->addCompleted("Installed package: {$pkg}");
        }

        $this->progress->phase('Directory staging');
        $this->createUserDirs($user);

        $this->progress->phase('Desktop configuration generation');
        $generated = $this->writeConfigs($user);

        $this->progress->phase('Session validation and UX/UI validation');
        $validation = $this->validator->validate($generated);
        $this->writeValidationReport($user, $validation);

        foreach ($validation->passes as $pass) {
            $this->report->addCompleted("UX pass: {$pass}");
        }
        foreach ($validation->warnings as $warning) {
            $this->report->addIssue("UX warning: {$warning}");
        }
        foreach ($validation->issues as $issue) {
            $this->report->addIssue("UX issue: {$issue}");
        }

        $this->progress->phase('Final summary');
        $this->finalize($user, $validation);

        return $validation->isAcceptable() ? ExitCode::OK : ExitCode::PARTIAL;
    }

    private function validatePreflight(EnvironmentState $state, UserTarget $user): void
    {
        if (!$state->isAlpine) {
            throw new CliException('Unsupported system: this script targets Alpine Linux only.', ExitCode::UNSUPPORTED);
        }
        if (!$state->isRoot) {
            throw new CliException('Root privileges are required.', ExitCode::PRIVILEGE);
        }
        if (!$state->apkAvailable) {
            throw new CliException('apk package manager not found.', ExitCode::PREFLIGHT);
        }
        if (!$state->reposReadable) {
            throw new CliException('/etc/apk/repositories is not readable.', ExitCode::PREFLIGHT);
        }
        if (!$state->networkOk) {
            throw new CliException('Network access test failed; repository operations would be unsafe.', ExitCode::NETWORK);
        }
        if (!is_dir($user->home) || !is_writable($user->home)) {
            throw new CliException("Target user home is not writable: {$user->home}", ExitCode::PREFLIGHT);
        }

        $this->logger->success("Environment validated for Alpine Linux");
        $this->logger->info("Target desktop user: {$user->username} ({$user->home})");

        if ($state->xorgInstalled) {
            $this->report->addNote('X11 components were already partially present');
        }
        if ($state->herbstInstalled) {
            $this->report->addNote('herbstluftwm was already installed');
        }
        if ($state->rofiInstalled) {
            $this->report->addNote('rofi was already installed');
        }
    }

    /**
     * @return array{0:string[],1:string[],2:string[]}
     */
    private function planPackages(): array
    {
        $requested = [
            'xorg-server',
            'xinit',
            'xf86-input-libinput',
            'xrandr',
            'xsetroot',
            'xauth',
            'dbus',
            'elogind',
            'polkit',

            'herbstluftwm',
            'rofi',
            'dunst',
            'feh',
            'picom',

            'alacritty',
            'font-dejavu',
            'ttf-dejavu',
            'noto-fonts',

            'pcmanfm',
            'xclip',
            'maim',
            'slop',
            'unzip',
            'zip',
            'p7zip',
            'tar',
            'file',

            'alsa-utils',
            'pamixer',
            'pipewire',
            'wireplumber',
            'pipewire-pulse',

            'adwaita-icon-theme',
            'shared-mime-info',
        ];

        $resolved = $this->packageManager->resolvePackages($requested);
        return [$resolved['installable'], $resolved['missing'], $resolved['alreadyInstalled']];
    }

    private function createUserDirs(UserTarget $user): void
    {
        $dirs = [
            $user->home . '/.config',
            $user->home . '/.config/herbstluftwm',
            $user->home . '/.config/rofi',
            $user->home . '/.config/dunst',
            $user->home . '/.config/alacritty',
            $user->home . '/.config/alpine-lapaz',
            $user->home . '/.local',
            $user->home . '/.local/bin',
            $user->home . '/.local/share',
            $user->home . '/.local/share/backgrounds',
        ];

        foreach ($dirs as $dir) {
            $this->files->ensureDir($dir, 0755, $user->uid, $user->gid);
            $this->report->addCompleted("Ensured directory: {$dir}");
        }
    }

    private function writeConfigs(UserTarget $user): array
    {
        $bg = '#1f2937';
        $fg = '#e5e7eb';
        $accent = '#94a3b8';
        $font = 'DejaVu Sans Mono';
        $fontSize = 11;

        $autostart = $this->buildHerbstAutostart($bg, $fg, $accent);
        $rofi = $this->buildRofiConfig($bg, $fg, $accent);
        $dunst = $this->buildDunstConfig($bg, $fg, $accent, $font, $fontSize);
        $alacritty = $this->buildAlacrittyConfig($bg, $fg, $font, $fontSize);
        $xresources = $this->buildXresources($bg, $fg, $accent, $font, $fontSize);
        $xinitrc = $this->buildXinitrc();
        $readme = $this->buildDesktopHelp();

        $writes = [
            [$user->home . '/.config/herbstluftwm/autostart', $autostart, 0755],
            [$user->home . '/.config/rofi/config.rasi', $rofi, 0644],
            [$user->home . '/.config/dunst/dunstrc', $dunst, 0644],
            [$user->home . '/.config/alacritty/alacritty.toml', $alacritty, 0644],
            [$user->home . '/.Xresources', $xresources, 0644],
            [$user->home . '/.xinitrc', $xinitrc, 0755],
            [$user->home . '/.config/alpine-lapaz/README.txt', $readme, 0644],
        ];

        foreach ($writes as [$path, $content, $mode]) {
            $changed = $this->files->writeAtomic($path, $content, $mode, $user->uid, $user->gid);
            if ($changed) {
                $this->report->addCompleted("Configured: {$path}");
            } else {
                $this->report->addSkipped("Unchanged: {$path}");
            }
        }

        return [
            'background' => $bg,
            'foreground' => $fg,
            'font_size' => $fontSize,
            'rofi_shortcut' => 'Mod4-d',
            'terminal_shortcut' => 'Mod4-Return',
            'notification_timeout' => 6000,
            'session_components' => ['dbus', 'feh', 'dunst', 'picom', 'herbstluftwm'],
            'theme_noise' => 'low',
            'keybindings_documented' => true,
            'immediate_usability' => true,
        ];
    }

    private function buildHerbstAutostart(string $bg, string $fg, string $accent): string
    {
        return <<<SH
#!/bin/sh
set -eu

export XDG_CURRENT_DESKTOP=herbstluftwm
export XDG_SESSION_TYPE=x11
export GTK_THEME=Adwaita:dark
export QT_QPA_PLATFORMTHEME=gtk2
export TERMINAL=alacritty
export BROWSER=firefox

if command -v xrdb >/dev/null 2>&1 && [ -f "\$HOME/.Xresources" ]; then
    xrdb -merge "\$HOME/.Xresources"
fi

if command -v xsetroot >/dev/null 2>&1; then
    xsetroot -cursor_name left_ptr -solid "{$bg}"
fi

if command -v dbus-update-activation-environment >/dev/null 2>&1; then
    dbus-update-activation-environment --systemd DISPLAY XAUTHORITY XDG_CURRENT_DESKTOP XDG_SESSION_TYPE || true
fi

if command -v pipewire >/dev/null 2>&1 && ! pgrep -u "\$(id -u)" -x pipewire >/dev/null 2>&1; then
    pipewire >/dev/null 2>&1 &
fi

if command -v wireplumber >/dev/null 2>&1 && ! pgrep -u "\$(id -u)" -x wireplumber >/dev/null 2>&1; then
    wireplumber >/dev/null 2>&1 &
fi

if command -v pipewire-pulse >/dev/null 2>&1 && ! pgrep -u "\$(id -u)" -x pipewire-pulse >/dev/null 2>&1; then
    pipewire-pulse >/dev/null 2>&1 &
fi

if command -v dunst >/dev/null 2>&1 && ! pgrep -u "\$(id -u)" -x dunst >/dev/null 2>&1; then
    dunst >/dev/null 2>&1 &
fi

if command -v picom >/dev/null 2>&1 && ! pgrep -u "\$(id -u)" -x picom >/dev/null 2>&1; then
    picom --config /dev/null --backend xrender --vsync >/dev/null 2>&1 &
fi

if command -v feh >/dev/null 2>&1; then
    if [ -f "\$HOME/.local/share/backgrounds/default-desktop-bg.png" ]; then
        feh --no-fehbg --bg-fill "\$HOME/.local/share/backgrounds/default-desktop-bg.png" >/dev/null 2>&1 || true
    else
        feh --no-fehbg --bg-fill /usr/share/backgrounds/* >/dev/null 2>&1 || true
    fi
fi

hc() { herbstclient "\$@" ; }

hc emit_hook reload

Mod=Mod4

hc keyunbind --all
hc mouseunbind --all
hc unrule -F

hc set frame_border_active_color "{$accent}"
hc set frame_border_normal_color "#4b5563"
hc set frame_bg_normal_color "{$bg}"
hc set frame_bg_active_color "{$bg}"
hc set frame_border_width 2
hc set window_border_width 2
hc set frame_gap 8
hc set window_gap 0
hc set smart_window_surroundings 1
hc set smart_frame_surroundings 1
hc set mouse_recenter_gap 0
hc set snap_gap 8
hc set focus_follows_mouse off
hc set tree_style '╾│ ├└╼─┐'

for i in 1 2 3 4 5 6 7 8 9; do
    hc add "\$i" || true
    hc keybind "\$Mod-\$i" use "\$i"
    hc keybind "\$Mod-Shift-\$i" move "\$i"
done

hc rename default main || true
hc use main

hc keybind \$Mod-Return spawn alacritty
hc keybind \$Mod-d spawn rofi -show drun
hc keybind \$Mod-Shift-q close
hc keybind \$Mod-q quit
hc keybind \$Mod-Shift-r reload
hc keybind \$Mod-c spawn pcmanfm
hc keybind \$Mod-Shift-s spawn "maim -s | xclip -selection clipboard -t image/png"
hc keybind \$Mod-Print spawn "maim | xclip -selection clipboard -t image/png"
hc keybind \$Mod-Shift-c spawn "pkill -x picom || picom --config /dev/null --backend xrender --vsync >/dev/null 2>&1 &"

hc keybind \$Mod-Left focus left
hc keybind \$Mod-Down focus down
hc keybind \$Mod-Up focus up
hc keybind \$Mod-Right focus right

hc keybind \$Mod-Shift-Left shift left
hc keybind \$Mod-Shift-Down shift down
hc keybind \$Mod-Shift-Up shift up
hc keybind \$Mod-Shift-Right shift right

hc keybind \$Mod-Control-Left resize left +0.05
hc keybind \$Mod-Control-Down resize down +0.05
hc keybind \$Mod-Control-Up resize up +0.05
hc keybind \$Mod-Control-Right resize right +0.05

hc keybind \$Mod-space cycle_layout +1
hc keybind \$Mod-f fullscreen toggle
hc keybind \$Mod-p pseudotile toggle
hc keybind \$Mod-s floating toggle
hc keybind \$Mod-Tab cycle_monitor
hc keybind \$Mod-period cycle_all +1
hc keybind \$Mod-comma cycle_all -1

hc mousebind \$Mod-Button1 move
hc mousebind \$Mod-Button2 zoom
hc mousebind \$Mod-Button3 resize

hc rule focus=on
hc rule windowtype~'_NET_WM_WINDOW_TYPE_(DIALOG|UTILITY|SPLASH)' floating=on
hc rule class='Pavucontrol' floating=on
hc rule class='feh' floating=on

exec herbstluftwm
SH;
    }

    private function buildRofiConfig(string $bg, string $fg, string $accent): string
    {
        return <<<RASI
configuration {
    modi: "drun,run,window";
    show-icons: true;
    display-drun: "Applications";
    display-run: "Run";
    display-window: "Windows";
    drun-display-format: "{name}";
    font: "DejaVu Sans 11";
    location: 0;
    disable-history: false;
    sidebar-mode: false;
    hover-select: true;
}

* {
    background: {$bg};
    background-alt: #111827;
    foreground: {$fg};
    selected: {$accent};
    urgent: #b45309;
    border-color: #4b5563;
}

window {
    width: 42%;
    border: 2px;
    border-color: @border-color;
    border-radius: 6px;
    background-color: @background;
    padding: 12px;
}

mainbox {
    spacing: 8px;
    children: [ inputbar, listview ];
}

inputbar {
    background-color: @background-alt;
    text-color: @foreground;
    border-radius: 4px;
    padding: 8px 10px;
    children: [ prompt, entry ];
}

prompt {
    text-color: @selected;
    padding: 0px 8px 0px 0px;
}

entry {
    text-color: @foreground;
    placeholder: "Search applications, commands, or windows";
    placeholder-color: #9ca3af;
}

listview {
    lines: 12;
    columns: 1;
    spacing: 4px;
    cycle: true;
    dynamic: true;
    scrollbar: true;
}

element {
    padding: 8px 10px;
    border-radius: 4px;
    background-color: transparent;
    text-color: @foreground;
}

element selected {
    background-color: #334155;
    text-color: @foreground;
}

element-text, element-icon {
    background-color: inherit;
    text-color: inherit;
}
RASI;
    }

    private function buildDunstConfig(string $bg, string $fg, string $accent, string $font, int $fontSize): string
    {
        return <<<INI
[global]
    monitor = 0
    follow = mouse
    width = 360
    height = 160
    origin = top-right
    offset = 16x16
    scale = 0
    notification_limit = 6
    progress_bar = true
    progress_bar_height = 10
    progress_bar_frame_width = 1
    progress_bar_min_width = 150
    progress_bar_max_width = 300
    indicate_hidden = yes
    transparency = 0
    separator_height = 2
    padding = 12
    horizontal_padding = 12
    text_icon_padding = 8
    frame_width = 2
    frame_color = "{$accent}"
    separator_color = frame
    sort = yes
    idle_threshold = 120
    font = {$font} {$fontSize}
    line_height = 2
    markup = full
    format = "<b>%s</b>\\n%b"
    alignment = left
    vertical_alignment = center
    word_wrap = yes
    show_age_threshold = 60
    ellipsize = middle
    stack_duplicates = true
    hide_duplicate_count = false
    show_indicators = yes
    icon_position = left
    min_icon_size = 20
    max_icon_size = 40
    sticky_history = yes
    history_length = 20
    browser = /usr/bin/xdg-open
    always_run_script = true
    title = Dunst
    class = Dunst
    corner_radius = 6
    ignore_dbusclose = false

[urgency_low]
    background = "{$bg}"
    foreground = "{$fg}"
    timeout = 6

[urgency_normal]
    background = "{$bg}"
    foreground = "{$fg}"
    timeout = 6

[urgency_critical]
    background = "#3f1d1d"
    foreground = "#f9fafb"
    frame_color = "#b91c1c"
    timeout = 0
INI;
    }

    private function buildAlacrittyConfig(string $bg, string $fg, string $font, int $fontSize): string
    {
        return <<<TOML
[window]
padding = { x = 10, y = 10 }
decorations = "full"
opacity = 1.0

[font]
normal = { family = "{$font}", style = "Regular" }
bold = { family = "{$font}", style = "Bold" }
italic = { family = "{$font}", style = "Italic" }
size = {$fontSize}

[colors.primary]
background = "{$bg}"
foreground = "{$fg}"

[colors.cursor]
text = "{$bg}"
cursor = "{$fg}"

[colors.normal]
black = "#111827"
red = "#7f1d1d"
green = "#166534"
yellow = "#92400e"
blue = "#1d4ed8"
magenta = "#6b21a8"
cyan = "#155e75"
white = "#d1d5db"

[colors.bright]
black = "#374151"
red = "#b91c1c"
green = "#15803d"
yellow = "#b45309"
blue = "#2563eb"
magenta = "#7e22ce"
cyan = "#0f766e"
white = "#f3f4f6"
TOML;
    }

    private function buildXresources(string $bg, string $fg, string $accent, string $font, int $fontSize): string
    {
        return <<<XRDB
Xcursor.size: 24
Xcursor.theme: Adwaita
*.font: xft:{$font}:pixelsize=15:antialias=true:hinting=true
*.foreground: {$fg}
*.background: {$bg}
*.cursorColor: {$fg}
*.color0: #111827
*.color1: #7f1d1d
*.color2: #166534
*.color3: #92400e
*.color4: #1d4ed8
*.color5: #6b21a8
*.color6: #155e75
*.color7: #d1d5db
*.color8: #374151
*.color9: #b91c1c
*.color10: #15803d
*.color11: #b45309
*.color12: #2563eb
*.color13: #7e22ce
*.color14: #0f766e
*.color15: #f3f4f6
XRDB;
    }

    private function buildXinitrc(): string
    {
        return <<<'SH'
#!/bin/sh
set -eu

export XDG_SESSION_TYPE=x11
export XDG_CURRENT_DESKTOP=herbstluftwm
export XDG_SESSION_DESKTOP=herbstluftwm
export GTK_USE_PORTAL=0

if command -v dbus-launch >/dev/null 2>&1; then
    eval "$(dbus-launch --sh-syntax --exit-with-session)"
fi

if command -v elogind-launch >/dev/null 2>&1; then
    exec elogind-launch sh -lc "$HOME/.config/herbstluftwm/autostart"
fi

exec sh -lc "$HOME/.config/herbstluftwm/autostart"
SH;
    }

    private function buildDesktopHelp(): string
    {
        return <<<TXT
alpine-lapaz desktop provisioner

Daily-use shortcuts
-------------------
Super + Enter         Open terminal
Super + d             Open launcher (rofi)
Super + c             Open file manager
Super + Shift + q     Close focused window
Super + Shift + r     Reload herbstluftwm config
Super + s             Toggle floating for focused window
Super + f             Toggle fullscreen
Super + Space         Cycle layout
Super + 1..9          Switch workspace
Super + Shift + 1..9  Move window to workspace
Super + Arrow keys    Focus neighboring window
Super + Shift + Arrow keys  Move window
Super + Ctrl + Arrow keys   Resize split
Super + Print         Full screenshot to clipboard
Super + Shift + s     Selection screenshot to clipboard

Startup
-------
Run: startx

Design choices
--------------
- conservative, readable colors
- neutral dark background with light foreground
- minimal visual noise
- no decorative transparency
- practical default bindings
- restrained notifications

If you later want deeper customization, first copy the current configuration
and preserve readability and contrast.
TXT;
    }

    private function writeValidationReport(UserTarget $user, UXValidationReport $validation): void
    {
        $lines = [];
        $lines[] = "UX/UI validation report";
        $lines[] = "Generated at: " . date('c');
        $lines[] = "";
        $lines[] = "Passes:";
        foreach ($validation->passes as $item) {
            $lines[] = "  - {$item}";
        }
        $lines[] = "";
        $lines[] = "Warnings:";
        foreach ($validation->warnings as $item) {
            $lines[] = "  - {$item}";
        }
        $lines[] = "";
        $lines[] = "Issues:";
        foreach ($validation->issues as $item) {
            $lines[] = "  - {$item}";
        }
        $lines[] = "";
        $lines[] = "Outcome: " . ($validation->isAcceptable() ? "ACCEPTABLE" : "NEEDS REVIEW");

        $this->files->writeAtomic(
            $user->home . '/.config/alpine-lapaz/ux-validation-report.txt',
            implode("\n", $lines) . "\n",
            0644,
            $user->uid,
            $user->gid
        );
    }

    private function finalize(UserTarget $user, UXValidationReport $validation): void
    {
        $this->logger->success('Provisioning completed');
        fwrite(STDOUT, "\n" . Ansi::BOLD . "Summary" . Ansi::RESET . "\n");
        fwrite(STDOUT, "-------\n");

        foreach ($this->report->completed as $item) {
            fwrite(STDOUT, Ansi::GREEN . "  [done] " . Ansi::RESET . $item . "\n");
        }
        foreach ($this->report->skipped as $item) {
            fwrite(STDOUT, Ansi::CYAN . "  [skip] " . Ansi::RESET . $item . "\n");
        }
        foreach ($this->report->issues as $item) {
            fwrite(STDOUT, Ansi::YELLOW . "  [note] " . Ansi::RESET . $item . "\n");
        }
        foreach ($this->report->notes as $item) {
            fwrite(STDOUT, Ansi::DIM . "  [info] " . Ansi::RESET . $item . "\n");
        }

        fwrite(STDOUT, "\nBackup transaction directory: " . $this->backupManager->dir() . "\n");
        fwrite(STDOUT, "Persistent log file: " . $this->logger->path() . "\n");
        fwrite(STDOUT, "UX/UI report: " . $user->home . "/.config/alpine-lapaz/ux-validation-report.txt\n");
        fwrite(STDOUT, "Quick help: " . $user->home . "/.config/alpine-lapaz/README.txt\n");

        fwrite(STDOUT, "\nManual next step:\n");
        fwrite(STDOUT, "  Log in as {$user->username} and run: startx\n");

        if (!$validation->isAcceptable()) {
            fwrite(STDOUT, Ansi::YELLOW . "\nProvisioning finished with UX/UI review notes.\n" . Ansi::RESET);
        } else {
            fwrite(STDOUT, Ansi::GREEN . "\nProvisioning finished successfully with acceptable UX/UI validation.\n" . Ansi::RESET);
        }
    }
}

function usage(): never
{
    $msg = <<<TXT
Usage:
  php alpine-lapaz.php [--user=username]

Options:
  --user=<name>   Explicit target desktop user
  --help          Show this help

Notes:
  - Run as root
  - Target system must be Alpine Linux
  - If multiple non-root desktop users exist, --user is required
TXT;
    fwrite(STDOUT, $msg . "\n");
    exit(ExitCode::USAGE);
}

function main(array $argv): int
{
    $requestedUser = null;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            usage();
        }
        if (str_starts_with($arg, '--user=')) {
            $requestedUser = substr($arg, 7);
            if ($requestedUser === '') {
                throw new CliException('Empty --user value is not allowed', ExitCode::USAGE);
            }
            continue;
        }
        throw new CliException("Unknown argument: {$arg}", ExitCode::USAGE);
    }

    $timestamp = date('Ymd-His');
    $logFile = "/var/log/alpine-lapaz/provision-{$timestamp}.log";
    $transactionDir = "/var/backups/alpine-lapaz/txn-{$timestamp}";

    $logger = new Logger($logFile);
    $progress = new ProgressRenderer(7, $logger);
    $runner = new CommandRunner($logger);
    $backupManager = new BackupManager($transactionDir, $logger);
    $fileManager = new FileManager($logger, $backupManager);
    $detector = new EnvironmentDetector($runner, $logger);
    $packageManager = new PackageManager($runner, $logger);
    $validator = new UXValidator();

    $provisioner = new Provisioner(
        $logger,
        $progress,
        $runner,
        $detector,
        $packageManager,
        $fileManager,
        $backupManager,
        $validator
    );

    try {
        return $provisioner->run($requestedUser);
    } catch (CliException $e) {
        $logger->error($e->getMessage());

        try {
            $backupManager->restoreAll();
        } catch (Throwable $rollbackError) {
            $logger->error('Rollback failed: ' . $rollbackError->getMessage());
            return ExitCode::ROLLBACK_FAILED;
        }

        return $e->exitCode();
    } catch (Throwable $e) {
        $logger->error('Unhandled fatal error: ' . $e->getMessage());

        try {
            $backupManager->restoreAll();
        } catch (Throwable $rollbackError) {
            $logger->error('Rollback failed: ' . $rollbackError->getMessage());
            return ExitCode::ROLLBACK_FAILED;
        }

        return ExitCode::INTERNAL;
    }
}

exit(main($argv));
