<?php

namespace App\Service;

use App\Entity\RateOverride;
use App\Entity\RateRule;
use App\Repository\RateOverrideRepository;
use App\Repository\RateRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

class RateService
{
    private float $defaultSpreadPercent = 2.5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateOverrideRepository $rateOverrideRepo,
        private readonly RateRuleRepository $rateRuleRepo
    ) {}

    // Mid (EUR -> LOCAL) par défaut. Les overrides BD prennent le dessus.
    private array $midRates = [
        'USD'=>1.08,'GBP'=>0.85,'CHF'=>0.96,'JPY'=>170.0,'CAD'=>1.47,'AUD'=>1.62,'NZD'=>1.78,
        'NOK'=>11.6,'SEK'=>11.4,'DKK'=>7.45,'PLN'=>4.3,'CZK'=>25.3,'HUF'=>395.0,'RON'=>4.98,'BGN'=>1.96,
        'TRY'=>36.0,'MAD'=>10.8,'TND'=>3.4,'EGP'=>54.0,'CNY'=>7.7,'XOF'=>655.96,'XAF'=>655.96,'ZAR'=>19.7,
    ];

    // Liste (pays/devise/flags + marge de fallback)
    private array $currencies = [
        ['code'=>'USD','currency'=>'US Dollar','country'=>'United States','flag'=>'🇺🇸','spread_percent'=>2.0],
        ['code'=>'GBP','currency'=>'Pound Sterling','country'=>'United Kingdom','flag'=>'🇬🇧','spread_percent'=>2.2],
        ['code'=>'CHF','currency'=>'Swiss Franc','country'=>'Switzerland','flag'=>'🇨🇭','spread_percent'=>2.0],
        ['code'=>'JPY','currency'=>'Japanese Yen','country'=>'Japan','flag'=>'🇯🇵','spread_percent'=>2.5],
        ['code'=>'CAD','currency'=>'Canadian Dollar','country'=>'Canada','flag'=>'🇨🇦','spread_percent'=>2.3],
        ['code'=>'AUD','currency'=>'Australian Dollar','country'=>'Australia','flag'=>'🇦🇺','spread_percent'=>2.3],
        ['code'=>'NZD','currency'=>'New Zealand Dollar','country'=>'New Zealand','flag'=>'🇳🇿','spread_percent'=>2.6],
        ['code'=>'NOK','currency'=>'Norwegian Krone','country'=>'Norway','flag'=>'🇳🇴','spread_percent'=>2.5],
        ['code'=>'SEK','currency'=>'Swedish Krona','country'=>'Sweden','flag'=>'🇸🇪','spread_percent'=>2.5],
        ['code'=>'DKK','currency'=>'Danish Krone','country'=>'Denmark','flag'=>'🇩🇰','spread_percent'=>2.0],
        ['code'=>'PLN','currency'=>'Polish Złoty','country'=>'Poland','flag'=>'🇵🇱','spread_percent'=>2.8],
        ['code'=>'CZK','currency'=>'Czech Koruna','country'=>'Czechia','flag'=>'🇨🇿','spread_percent'=>2.8],
        ['code'=>'HUF','currency'=>'Hungarian Forint','country'=>'Hungary','flag'=>'🇭🇺','spread_percent'=>3.0],
        ['code'=>'RON','currency'=>'Romanian Leu','country'=>'Romania','flag'=>'🇷🇴','spread_percent'=>3.0],
        ['code'=>'BGN','currency'=>'Bulgarian Lev','country'=>'Bulgaria','flag'=>'🇧🇬','spread_percent'=>3.0],
        ['code'=>'TRY','currency'=>'Turkish Lira','country'=>'Türkiye','flag'=>'🇹🇷','spread_percent'=>4.0],
        ['code'=>'MAD','currency'=>'Moroccan Dirham','country'=>'Morocco','flag'=>'🇲🇦','spread_percent'=>3.5],
        ['code'=>'TND','currency'=>'Tunisian Dinar','country'=>'Tunisia','flag'=>'🇹🇳','spread_percent'=>3.5],
        ['code'=>'EGP','currency'=>'Egyptian Pound','country'=>'Egypt','flag'=>'🇪🇬','spread_percent'=>4.0],
        ['code'=>'CNY','currency'=>'Chinese Yuan','country'=>'China','flag'=>'🇨🇳','spread_percent'=>3.0],
        ['code'=>'XOF','currency'=>'Franc CFA (UEMOA)','country'=>'Afrique de l’Ouest','flag'=>'🇸🇳','spread_percent'=>3.0],
        ['code'=>'XAF','currency'=>'Franc CFA (CEMAC)','country'=>'Afrique centrale','flag'=>'🇨🇲','spread_percent'=>3.0],
        ['code'=>'ZAR','currency'=>'Rand','country'=>'Afrique du Sud','flag'=>'🇿🇦','spread_percent'=>3.2],
    ];

    public function getSupportedCurrencies(): array
    {
        return $this->currencies;
    }

    private ?array $overrideCache = null;

    private function loadOverrides(): array
    {
        if ($this->overrideCache !== null) {
            return $this->overrideCache;
        }
        $list = $this->rateOverrideRepo->createQueryBuilder('r')->getQuery()->getResult();
        $map = [];
        foreach ($list as $o) {
            /** @var RateOverride $o */
            $map[$o->getCode()] = $o->getValue();
        }
        return $this->overrideCache = $map;
    }

    public function getCurrentOverrides(): array
    {
        return $this->loadOverrides();
    }

    // UPSERT des overrides (évite les doublons)
    public function saveOverrides(array $overrides): void
    {
        foreach ($overrides as $code => $value) {
            $val = (float) $value;

            // si valeur non positive: on supprime l'override existant (optionnel)
            if ($val <= 0) {
                if ($ex = $this->rateOverrideRepo->findOneBy(['code' => $code])) {
                    $this->em->remove($ex);
                }
                continue;
            }

            $o = $this->rateOverrideRepo->findOneBy(['code' => $code]);
            if (!$o) {
                $o = new RateOverride();
                $o->setCode($code);
            }
            $o->setValue($val);
            $this->em->persist($o);
        }
        $this->em->flush();
        $this->overrideCache = null;
    }

    private function getMid(string $code): ?float
    {
        $ov = $this->loadOverrides();
        if (isset($ov[$code]) && (float) $ov[$code] > 0) {
            return (float) $ov[$code];
        }
        return $this->midRates[$code] ?? null;
    }

    private function getRule(string $code): ?RateRule
    {
        return $this->rateRuleRepo->findOneByCode($code);
    }

    // Calcule Buy/Sell en tenant compte du mode: manual | percent | fallback spread
    public function computeBuySell(string $code): array
    {
        $mid = $this->getMid($code);
        if ($mid === null) {
            return ['mid' => null, 'buy' => null, 'sell' => null];
        }

        $rule = $this->getRule($code);
        if ($rule) {
            if ($rule->getMode() === 'manual'
                && $rule->getManualBuy() !== null
                && $rule->getManualSell() !== null) {
                return ['mid' => $mid, 'buy' => $rule->getManualBuy(), 'sell' => $rule->getManualSell()];
            }
            if ($rule->getMode() === 'percent') {
                $pb = max(0.0, (float) ($rule->getPercentBuy()  ?? 0.0));
                $ps = max(0.0, (float) ($rule->getPercentSell() ?? 0.0));
                $buy  = $mid * (1.0 - $pb / 100.0);
                $sell = $mid * (1.0 + $ps / 100.0);
                return ['mid' => $mid, 'buy' => $buy, 'sell' => $sell];
            }
        }

        // Fallback: spread (marge) configurée
        $spread = $this->getSpreadPercentFor($code) / 100.0;
        return ['mid' => $mid, 'buy' => $mid * (1 - $spread), 'sell' => $mid * (1 + $spread)];
    }

    public function convertEuroToLocal(string $code, float $amountEuro): float
    {
        $r = $this->computeBuySell($code);
        return $amountEuro * ($r['sell'] ?? 0.0);
    }

    public function convertLocalToEuro(string $code, float $amountLocal): float
    {
        $r = $this->computeBuySell($code);
        $buy = $r['buy'] ?? 0.0;
        return $buy > 0 ? ($amountLocal / $buy) : 0.0;
    }

    private function getSpreadPercentFor(string $code): float
    {
        foreach ($this->currencies as $c) {
            if ($c['code'] === $code) {
                return (float) ($c['spread_percent'] ?? $this->defaultSpreadPercent);
            }
        }
        return $this->defaultSpreadPercent;
    }
}
