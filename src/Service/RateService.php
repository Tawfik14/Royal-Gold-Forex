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

    
    private array $midRates = [
        'USD'=>1.08,'GBP'=>0.85,'CHF'=>0.96,'JPY'=>170.0,'CAD'=>1.47,'AUD'=>1.62,'NZD'=>1.78,
        'NOK'=>11.6,'SEK'=>11.4,'DKK'=>7.45,'PLN'=>4.3,'CZK'=>25.3,'HUF'=>395.0,'RON'=>4.98,'BGN'=>1.96,
        'TRY'=>36.0,'MAD'=>10.8,'TND'=>3.4,'EGP'=>54.0,'CNY'=>7.7,'XOF'=>655.96,'XAF'=>655.96,'ZAR'=>19.7,
        'AED'=>3.97,
        'HKD'=>8.42,
        'RUB'=>97.0,
        'SAR'=>4.05,
        'THB'=>39.0,
    ];

   
    private array $currencies = [
        ['code'=>'USD','currency'=>'Dollar américain','country'=>'États-Unis','flag'=>'🇺🇸','spread_percent'=>2.0],
        ['code'=>'GBP','currency'=>'Livre sterling','country'=>'Royaume-Uni','flag'=>'🇬🇧','spread_percent'=>2.2],
        ['code'=>'CHF','currency'=>'Franc suisse','country'=>'Suisse','flag'=>'🇨🇭','spread_percent'=>2.0],
        ['code'=>'JPY','currency'=>'Yen japonais','country'=>'Japon','flag'=>'🇯🇵','spread_percent'=>2.5],
        ['code'=>'CAD','currency'=>'Dollar canadien','country'=>'Canada','flag'=>'🇨🇦','spread_percent'=>2.3],
        ['code'=>'AUD','currency'=>'Dollar australien','country'=>'Australie','flag'=>'🇦🇺','spread_percent'=>2.3],
        ['code'=>'NZD','currency'=>'Dollar néo-zélandais','country'=>'Nouvelle-Zélande','flag'=>'🇳🇿','spread_percent'=>2.6],
        ['code'=>'NOK','currency'=>'Couronne norvégienne','country'=>'Norvège','flag'=>'🇳🇴','spread_percent'=>2.5],
        ['code'=>'SEK','currency'=>'Couronne suédoise','country'=>'Suède','flag'=>'🇸🇪','spread_percent'=>2.5],
        ['code'=>'DKK','currency'=>'Couronne danoise','country'=>'Danemark','flag'=>'🇩🇰','spread_percent'=>2.0],
        ['code'=>'PLN','currency'=>'Zloty polonais','country'=>'Pologne','flag'=>'🇵🇱','spread_percent'=>2.8],
        ['code'=>'CZK','currency'=>'Couronne tchèque','country'=>'République tchèque','flag'=>'🇨🇿','spread_percent'=>2.8],
        ['code'=>'HUF','currency'=>'Forint hongrois','country'=>'Hongrie','flag'=>'🇭🇺','spread_percent'=>3.0],
        ['code'=>'RON','currency'=>'Leu roumain','country'=>'Roumanie','flag'=>'🇷🇴','spread_percent'=>3.0],
        ['code'=>'BGN','currency'=>'Lev bulgare','country'=>'Bulgarie','flag'=>'🇧🇬','spread_percent'=>3.0],
        ['code'=>'TRY','currency'=>'Livre turque','country'=>'Turquie','flag'=>'🇹🇷','spread_percent'=>4.0],
        ['code'=>'MAD','currency'=>'Dirham marocain','country'=>'Maroc','flag'=>'🇲🇦','spread_percent'=>3.5],
        ['code'=>'TND','currency'=>'Dinar tunisien','country'=>'Tunisie','flag'=>'🇹🇳','spread_percent'=>3.5],
        ['code'=>'EGP','currency'=>'Livre égyptienne','country'=>'Égypte','flag'=>'🇪🇬','spread_percent'=>4.0],
        ['code'=>'CNY','currency'=>'Yuan renminbi','country'=>'Chine','flag'=>'🇨🇳','spread_percent'=>3.0],
        ['code'=>'XOF','currency'=>'Franc CFA (UEMOA)','country'=>'Afrique de l’Ouest','flag'=>'🇸🇳','spread_percent'=>3.0],
        ['code'=>'XAF','currency'=>'Franc CFA (CEMAC)','country'=>'Afrique centrale','flag'=>'🇨🇲','spread_percent'=>3.0],
        ['code'=>'ZAR','currency'=>'Rand sud-africain','country'=>'Afrique du Sud','flag'=>'🇿🇦','spread_percent'=>3.2],
        ['code'=>'AED','currency'=>'Dirham des Émirats arabes unis','country'=>'Émirats arabes unis','flag'=>'🇦🇪','spread_percent'=>2.6],
        ['code'=>'HKD','currency'=>'Dollar de Hong Kong','country'=>'Hong Kong','flag'=>'🇭🇰','spread_percent'=>2.8],
        ['code'=>'RUB','currency'=>'Rouble russe','country'=>'Russie','flag'=>'🇷🇺','spread_percent'=>3.5],
        ['code'=>'SAR','currency'=>'Riyal saoudien','country'=>'Arabie saoudite','flag'=>'🇸🇦','spread_percent'=>2.6],
        ['code'=>'THB','currency'=>'Baht thaïlandais','country'=>'Thaïlande','flag'=>'🇹🇭','spread_percent'=>3.0],
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
        $this->overrideCache = $map;
        return $map;
    }

    // Règles précises par devise mode manuel ou mode devise
    private ?array $ruleCache = null;

    private function loadRules(): array
    {
        if ($this->ruleCache !== null) {
            return $this->ruleCache;
        }
        $list = $this->rateRuleRepo->createQueryBuilder('r')->getQuery()->getResult();
        $map = [];
        foreach ($list as $r) {
            /** @var RateRule $r */
            $map[$r->getCode()] = $r;
        }
        $this->ruleCache = $map;
        return $map;
    }

    public function getRule(string $code): ?RateRule
    {
        $rules = $this->loadRules();
        return $rules[$code] ?? null;
    }

    
    public function saveOverrides(array $overrides): void
    {
        foreach ($overrides as $code => $value) {
            $val = (float) $value;

            
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

    // Calcule Achat/Vente en tenant compte du mode: manual, percent et autre
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

        
        $spread = $this->getSpreadPercentFor($code) / 100.0;
        return ['mid' => $mid, 'buy' => $mid * (1 - $spread), 'sell' => $mid * (1 + $spread)];
    }

    // Conversion depuis EUR vers devise (vente)
    public function convertFromEur(string $code, float $amountEur): float
    {
        $r = $this->computeBuySell($code);
        $sell = $r['sell'] ?? 0.0;
        return $sell > 0 ? ($amountEur * $sell) : 0.0;
    }

    // Conversion vers EUR depuis devise (achat)
    public function convertToEur(string $code, float $amountLocal): float
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

