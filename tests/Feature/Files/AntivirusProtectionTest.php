<?php

use App\Domain\Files\AntivirusProtection;
use App\Domain\Files\AttachmentScanner;
use App\Domain\Files\Exceptions\AntivirusUnavailable;
use App\Domain\Files\Exceptions\FileRejected;
use App\Domain\Files\FileGate;
use App\Domain\Files\ScanVerdict;
use App\Filament\Pages\SystemStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
 * Д-28 — the antivirus check of uploaded files is a system setting: the super admin turns it on and off in the
 * panel; off is the starting state; every change is journaled.
 */

beforeEach(function () {
    // A scanner that finds a threat in everything — so that "off" is told from "on" at once.
    $this->scanner = function (bool $reachable): void {
        app()->bind(AttachmentScanner::class, fn (): AttachmentScanner => new class($reachable) implements AttachmentScanner
        {
            public function __construct(private readonly bool $reachable) {}

            public function scan(string $absolutePath): ScanVerdict
            {
                return $this->reachable ? ScanVerdict::Infected : ScanVerdict::Unavailable;
            }

            public function reachable(): bool
            {
                return $this->reachable;
            }
        });
    };
    $this->file = tempnam(sys_get_temp_dir(), 'av');
    file_put_contents($this->file, 'conținut');
});

it('is off until the super admin turns it on, and then every file is checked', function () {
    ($this->scanner)(true);
    $admin = userWithRoles('super_admin');

    expect(app(AntivirusProtection::class)->enabled())->toBeFalse()
        ->and(app(FileGate::class)->ensureAcceptable($this->file, 'plan.pdf'))->toBe(ScanVerdict::Skipped);

    app(AntivirusProtection::class)->set($admin, true);

    expect(app(AntivirusProtection::class)->enabled())->toBeTrue()
        ->and(fn () => app(FileGate::class)->ensureAcceptable($this->file, 'plan.pdf'))->toThrow(FileRejected::class)
        ->and(journalCount('files.antivirus.enabled'))->toBe(1);

    // Turning it on twice changes nothing and writes nothing.
    app(AntivirusProtection::class)->set($admin, true);
    expect(journalCount('files.antivirus.enabled'))->toBe(1);

    app(AntivirusProtection::class)->set($admin, false);

    expect(app(AntivirusProtection::class)->enabled())->toBeFalse()
        ->and(app(FileGate::class)->ensureAcceptable($this->file, 'plan.pdf'))->toBe(ScanVerdict::Skipped)
        ->and(journalCount('files.antivirus.disabled'))->toBe(1);
});

it('is not turned on while the antivirus service does not answer', function () {
    ($this->scanner)(false);

    expect(fn () => app(AntivirusProtection::class)->set(userWithRoles('super_admin'), true))->toThrow(AntivirusUnavailable::class)
        ->and(app(AntivirusProtection::class)->enabled())->toBeFalse()
        ->and(journalCount('files.antivirus.enabled'))->toBe(0);
});

it('is switched by the super admin only', function (string $role) {
    ($this->scanner)(true);

    expect(fn () => app(AntivirusProtection::class)->set(userWithRoles($role), true))->toThrow(AuthorizationException::class)
        ->and(app(AntivirusProtection::class)->enabled())->toBeFalse();
})->with(['org_head', 'unit_head', 'security', 'hr', 'employee']);

it('is switched on the system status page', function () {
    ($this->scanner)(true);
    $this->actingAs(userWithRoles('super_admin'));

    Livewire::test(SystemStatus::class)
        ->assertSee(__('system_status.antivirus'))->assertSee(__('system_status.antivirus_off'))
        ->assertSet('antivirusEnabled', false)->assertSet('antivirusReachable', true)
        ->callAction('switchAntivirus')
        ->assertSet('antivirusEnabled', true)->assertSee(__('system_status.antivirus_on'))
        ->callAction('switchAntivirus')
        ->assertSet('antivirusEnabled', false);

    expect(journalCount('files.antivirus.enabled'))->toBe(1)->and(journalCount('files.antivirus.disabled'))->toBe(1);
});

it('says on the page why it cannot be turned on', function () {
    ($this->scanner)(false);
    $this->actingAs(userWithRoles('super_admin'));

    Livewire::test(SystemStatus::class)
        ->assertSee(__('system_status.antivirus_service_down'))
        ->callAction('switchAntivirus')
        ->assertNotified(__('files.errors.antivirus_unreachable'))
        ->assertSet('antivirusEnabled', false);
});
