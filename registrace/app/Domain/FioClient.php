<?php
declare(strict_types=1);

namespace Bkl\Domain;

/**
 * Čtení pohybů z Fio API (token „jen sledování účtu“).
 * Limit Fio: 1 dotaz za 30 s na token → stahujeme vždy za období (posledních N dní);
 * díky jedinečnému ID pohybu opakované stažení nic nezdvojí a po výpadku se nic neztratí.
 */
class FioClient
{
    public function __construct(private string $token, private string $baseUrl = 'https://fioapi.fio.cz/v1/rest')
    {
    }

    /** @return list<array{id:string,amount:int,currency:string,vs:string,date:string,account:string,name:string,message:string,raw:array<string,mixed>}> */
    public function transactions(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $url = sprintf('%s/periods/%s/%s/%s/transactions.json', $this->baseUrl, rawurlencode($this->token), $from->format('Y-m-d'), $to->format('Y-m-d'));
        [$code, $body] = $this->get($url);
        if ($code === 409) {
            throw new FioThrottled('Fio API: příliš častý dotaz (limit 30 s), zkusí se při dalším běhu.');
        }
        if ($code !== 200) {
            throw new \RuntimeException('Fio API vrátilo chybu ' . $code . '. Zkontrolujte token v config.php.');
        }
        return self::parse($body);
    }

    /** @return array{0:int,1:string} */
    protected function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'BKL-Registrace/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Fio API je nedostupné: ' . $err);
        }
        return [$code, (string) $body];
    }

    /** @return list<array{id:string,amount:int,currency:string,vs:string,date:string,account:string,name:string,message:string,raw:array<string,mixed>}> */
    public static function parse(string $json): array
    {
        $d = json_decode($json, true);
        if (!is_array($d)) {
            throw new \RuntimeException('Fio API vrátilo neplatná data.');
        }
        $list = $d['accountStatement']['transactionList']['transaction'] ?? [];
        $out = [];
        foreach ((array) $list as $t) {
            $g = static function (string $col) use ($t): string {
                $v = $t[$col]['value'] ?? null;
                return $v === null ? '' : (string) $v;
            };
            $id = $g('column22');
            if ($id === '') {
                continue;
            }
            $date = substr($g('column0'), 0, 10);
            $acc = $g('column2');
            if ($acc !== '' && $g('column3') !== '') {
                $acc .= '/' . $g('column3');
            }
            $out[] = [
                'id' => $id,
                'amount' => (int) round(((float) $g('column1')) * 100),
                'currency' => $g('column14') ?: 'CZK',
                'vs' => $g('column5'),
                'date' => ($date ?: date('Y-m-d')) . ' 00:00:00',
                'account' => $acc,
                'name' => $g('column10'),
                'message' => $g('column16'),
                'raw' => ['id' => $id, 'date' => $date, 'amount' => $g('column1'), 'vs' => $g('column5'), 'ks' => $g('column4'), 'ss' => $g('column6'), 'type' => $g('column8')],
            ];
        }
        return $out;
    }
}
