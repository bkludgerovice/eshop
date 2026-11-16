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
        $config ??= Config::load($root . '/config/config.php');
        $db ??= Db::fromConfig($config);
        return new self($config, $db, $root);
    }

    public function view(): View
    {
        return new View($this->root . '/templates', $this->config);
    }
}
