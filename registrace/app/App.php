<?php
declare(strict_types=1);

namespace Bkl;

use Bkl\Domain\EventRepo;
use Bkl\Domain\PaymentService;
use Bkl\Domain\RegistrationService;
use Bkl\Mail\Mailer;
use Bkl\Mail\Notifier;

/** Sestavení služeb aplikace (jednoduchý kontejner). */
final class App
{
    public Log $log;
    public EventRepo $events;
    public Mailer $mailer;
    public Notifier $notifier;
    public PaymentService $payments;
    public RegistrationService $registrations;

    public function __construct(public Config $config, public Db $db, public string $root)
    {
        $this->log = new Log($db);
        $this->events = new EventRepo($db);
        $this->mailer = new Mailer($db, $config, $this->log, $root . '/storage/mail');
        $this->notifier = new Notifier($db, $this->events, $this->mailer, $config);
        $this->payments = new PaymentService($db, $this->events, $this->log, function (string $type, int $orderId): void {
            if ($type === 'platba_prijata') {
                $this->notifier->paymentReceived($orderId);
            }
        });
        $this->registrations = new RegistrationService($db, $this->events, $this->log, function (int $orderId): void {
            $this->notifier->orderCreated($orderId);
        });
    }

    public static function boot(string $root, ?Config $config = null, ?Db $db = null): self
    {
        date_default_timezone_set(Util::TZ);
        if (PHP_SAPI !== 'cli') {
            ini_set('display_errors', '0');
        }
        $config ??= Config::load($root . '/config/config.php');
        $db ??= Db::fromConfig($config);
        $app = new self($config, $db, $root);
        $app->installErrorHandler();
        return $app;
    }

    /**
     * Upozornění novějších verzí PHP (deprecated) jen zapíšeme do logu – nic se nevypisuje a nic nespadne.
     * Ostatní varování převedeme na výjimku, kterou zachytí Router a zobrazí obecnou chybovou stránku.
     */
    public function installErrorHandler(): void
    {
        $seen = [];
        set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) use (&$seen): bool {
            if (!(error_reporting() & $no)) {
                return true; // potlačeno operátorem @
            }
            if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                $key = md5($file . $line);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    error_log('[BKL] deprecated: ' . $msg . ' @ ' . basename($file) . ':' . $line);
                }
                return true;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    public function view(): View
    {
        return new View($this->root . '/templates', $this->config);
    }
}
