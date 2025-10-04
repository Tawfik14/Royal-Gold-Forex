<?php

namespace App\Service;

use App\Entity\RateOverride;
use App\Entity\RateRule;
use App\Repository\RateOverrideRepository;
use App\Repository\RateRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CurrencyApi; // ⬅️ ajouté pour récupérer le spot

class RateService
{
    private float $defaultSpreadPercent = 2.5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateOverrideRepository $rateOverrideRepo,
        private readonly RateRuleRepository $rateRuleRepo,
        private readonly CurrencyApi $api // ⬅️ injecté (autowire)
    ) {}

    // Taux "mid" (EUR -> devise) — fallback statique
    private array $midRates = [
        'USD'=>1.08,'GBP'=>0.85,'CHF'=>0.96,'JPY'=>170.0,'CAD'=>1.47,'AUD'=>1.62,'NZD'=>1.78,
        'NOK'=>11.6,'SEK'=>11.4,'DKK'=>7.45,'PLN'=>4.3,'CZK'=>25.3,'HUF'=>395.0,'RON'=>4.98,'BGN'=>1.96,
        'TRY'=>36.0,'MAD'=>10.8,'TND'=>3.4,'EGP'=>54.0,'CNY'=>7.7,'XOF'=>655.96,'XAF'=>655.96,'ZAR'=>19.7,
        'AED'=>3.97,'HKD'=>8.42,'RUB'=>97.0,'SAR'=>4.05,'THB'=>39.0,'INR'=>90.0,
    ];

    // Métadonnées des devises (dont spread par défaut)
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
        ['code'=>'INR','currency'=>'Roupie indienne','country'=>'Inde','flag'=>'🇮🇳','spread_percent'=>3.0],
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

    // Règles précises par devise
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

            // Si <= 0 : suppression de l’override existant
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
        // 1) override admin
        $ov = $this->loadOverrides();
        if (isset($ov[$code]) && (float) $ov[$code] > 0) {
            return (float) $ov[$code];
        }
        // 2) fallback statique
        return $this->midRates[$code] ?? null;
    }

    /** Spot live EUR->CODE depuis l’API (null si indispo) */
    private function getSpot(string $code): ?float
    {
        $spots = $this->api->getEurSpots(300); // ex: ['USD'=>1.08, ...]
        $v = $spots[$code] ?? null;
        return ($v !== null && $v > 0) ? (float)$v : null;
    }

    // Calcule Achat/Vente en tenant compte du mode: manual, percent, fallback
    public function computeBuySell(string $code): array
    {
        $mid = $this->getMid($code);
        if ($mid === null) {
            return ['mid' => null, 'buy' => null, 'sell' => null];
        }

        $rule = $this->getRule($code);
        if ($rule) {
            // MANUEL
            if ($rule->getMode() === 'manual'
                && $rule->getManualBuy() !== null
                && $rule->getManualSell() !== null) {
                return ['mid' => $mid, 'buy' => $rule->getManualBuy(), 'sell' => $rule->getManualSell()];
            }

            // POURCENTAGE — ➜ **calculé sur le SPOT**, sinon mid si spot indispo
            if ($rule->getMode() === 'percent') {
                $pb = max(0.0, (float) ($rule->getPercentBuy()  ?? 0.0));
                $ps = max(0.0, (float) ($rule->getPercentSell() ?? 0.0));

                // base = spot si dispo, sinon mid (comportement demandé : "juste remplacer mid par spot")
                $base = $this->getSpot($code) ?? $mid;

                $buy  = $base * (1.0 - $pb / 100.0);
                $sell = $base * (1.0 + $ps / 100.0);
                return ['mid' => $mid, 'buy' => $buy, 'sell' => $sell];
            }
        }

        // Fallback: spread par défaut (spécifique à la devise ou global)
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

    /** Fiche courte d'une devise */
    public function getCurrencyMeta(string $code): ?array
    {
        $code = strtoupper($code);
        foreach ($this->currencies as $c) {
            if ($c['code'] === $code) {
                return $c;
            }
        }
        return null;
    }

    /** Contenu éditorial (inchangé) */
    public function getCurrencyArticle(string $code): string
    {
        $code = strtoupper($code);
        $mk = fn(string $html) => trim($html);

        switch ($code) {
            case 'USD': return $mk(<<<'HTML'
<h3>Pourquoi le dollar reste incontournable</h3>
<p>Le dollar américain est la monnaie la plus échangée au monde. Pour un voyage aux États-Unis, gardez à l’esprit que la carte bancaire est acceptée partout, mais l’espèce reste pratique pour les pourboires (tips), certains péages et les petits commerces.</p>
<p>Les prix s’affichent hors taxes dans la plupart des boutiques : au moment du paiement, une taxe locale s’ajoute. Cela surprend souvent les voyageurs européens habitués aux prix TTC.</p>

<h3>Billets en circulation : ce que vous verrez vraiment</h3>
<p>Dans la vie courante, vous croiserez surtout les billets de <strong>$1</strong>, <strong>$5</strong>, <strong>$10</strong>, <strong>$20</strong> et <strong>$100</strong>. Le <strong>$2</strong> existe, mais il est rare et parfois recherché comme curiosité. Les billets récents intègrent des éléments de sécurité (encre changeante, filigrane, bande 3D sur le $100).</p>

<h3>Astuce pour payer malin</h3>
<ul>
  <li>Les restaurants attendent un pourboire de 15–20 % ; certaines additions proposent des boutons « gratuity » en un clic.</li>
  <li>Les stations-service peuvent pré-autoriser un montant élevé : surveillez votre plafond.</li>
  <li>Les hôtels bloquent une caution (hold) sur la carte ; elle se libère après le check-out.</li>
</ul>

<hr>

<h3>Changer vos euros en dollars, sans stress</h3>
<p>Sur cette page, le panneau <em>Vente</em> convertit des EUR vers <strong>USD</strong>, et le panneau <em>Achat</em> l’inverse. Les deux taux sont volontairement différents : ils intègrent le <em>spread</em> de change, classique dans toute activité de bureau de change.</p>
<p>Pour les montants importants, apportez une pièce d’identité et prévenez-nous si vous souhaitez des petites coupures (idéal pour les pourboires et distributeurs automatiques).</p>

<h3>Bon à savoir avant de partir</h3>
<p>Aux États-Unis, le format décimal utilise le point <code>.</code> (ex. <code>$12.50</code>). Les distributeurs (ATM) facturent parfois une surcharge ; votre banque peut ajouter des frais. Un petit stock d’espèces + une carte sans frais à l’étranger, c’est l’équilibre parfait.</p>

<blockquote>
  <strong>Conseil express :</strong> évitez les billets très abîmés ; certains commerces les refusent par prudence.
</blockquote>
HTML);
            case 'GBP': return $mk(<<<'HTML'
<h3>Une monnaie historique, désormais en billets polymère</h3>
<p>La livre sterling est la monnaie du Royaume-Uni. Depuis quelques années, les billets sont en polymère : plus propres, plus durables, et truffés d’éléments de sécurité. En Écosse et en Irlande du Nord, des banques commerciales émettent leurs propres billets : ils sont légaux dans tout le Royaume-Uni, mais peuvent être discutés à Londres.</p>

<h3>Paiements et habitudes locales</h3>
<p>Le sans-contact est roi. Le cash sert surtout pour les transports régionaux, marchés et parkings. Les pubs prennent la carte, mais un peu d’espèces accélère le service dans les zones rurales.</p>

<h3>Préparer ses coupures</h3>
<ul>
  <li>Billets courants : £5, £10, £20, £50.</li>
  <li>Pratique : gardez des pièces pour les bus au-delà du réseau londonien.</li>
  <li>Les pourboires ne sont pas obligatoires partout ; visez 10–12,5 % si le service n’est pas inclus.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ GBP sereinement</h3>
<p>Utilisez le convertisseur ci-dessus. Le taux <em>vente</em> s’applique si vous partez avec des livres, le taux <em>achat</em> si vous revenez et nous les revendez. Pour de grosses sommes, dites-nous si vous préférez des coupures mixtes (ex. moitié £20, moitié £10) : c’est plus pratique au quotidien.</p>
HTML);
            case 'CHF': return $mk(<<<'HTML'
<h3>Le franc suisse : précision et sécurité</h3>
<p>Réputé pour sa stabilité, le franc suisse circule en billets très sécurisés (série 9). Les paiements par carte sont généralisés, mais la montagne et certains refuges restent favorables au cash.</p>

<h3>Billets & usage</h3>
<p>Vous verrez surtout CHF 10, 20, 50 et 100. Les grosses coupures (200 et 1000) existent et sont respectées, mais pas idéales pour les petites dépenses.</p>

<h3>Conseils pratiques</h3>
<ul>
  <li>Transport et confiseries : souvent sans-contact, mais gardez quelques pièces.</li>
  <li>En station de ski, certaines caisses ou automates préfèrent l’espèce.</li>
  <li>Le coût de la vie est élevé : anticipez votre budget en amont avec le convertisseur.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ CHF</h3>
<p>Le spread dépend des conditions de marché. Pour des montants élevés ou des besoins récurrents (résidents frontaliers), passez en agence : on peut organiser des retraits sur mesure.</p>
HTML);
            case 'JPY': return $mk(<<<'HTML'
<h3>Yen japonais : royaume du cash… qui se modernise</h3>
<p>Le Japon reste très « cash-friendly » : temples, petites auberges, distributeurs de billets, nombreux restos de quartier. Les grandes chaînes et transports urbains acceptent de mieux en mieux la carte.</p>

<h3>Comprendre les montants</h3>
<p>Les prix s’expriment en grands nombres (ex. 1 000 ¥) mais la conversion vers l’euro est simple avec le calculateur ci-dessus. Beaucoup de distributeurs affichent des frais fixes ; comparez si possible.</p>

<h3>Avant de partir</h3>
<ul>
  <li>Prévoyez des coupures de 1 000 ¥ et 5 000 ¥ pour la vie quotidienne.</li>
  <li>Les pièces de 100/500 ¥ servent beaucoup dans les automates.</li>
  <li>Gardez une enveloppe de secours en cash pour les zones rurales.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ JPY</h3>
<p>Utilisez nos blocs « Achat » et « Vente ». Si vous revenez avec des yens, apportez-les propres et non froissés : la vérification est plus rapide et évite les refus ponctuels de certaines banques japonaises.</p>
HTML);
            case 'AED': return $mk(<<<'HTML'
<h3>Dirham des Émirats : pratique pour Dubaï et Abu Dhabi</h3>
<p>Carte et espèces cohabitent très bien. Dans les centres commerciaux et hôtels, la carte domine ; pour taxis, souks et petits restos, des dirhams en poche fluidifient l’expérience.</p>

<h3>Conseils express</h3>
<ul>
  <li>Évitez les billets dégradés : certaines caisses refusent les coupures abîmées.</li>
  <li>Dans les taxis, demandez si le terminal carte fonctionne <em>avant</em> de monter.</li>
  <li>Préparez de la petite monnaie pour pourboires et parkings.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ AED ici</h3>
<p>Servez-vous du convertisseur : « Vente » pour partir avec des AED, « Achat » pour nous revendre vos billets au retour. Pour un voyage en famille, on peut panacher les coupures à votre demande.</p>
HTML);
            case 'MAD': return $mk(<<<'HTML'
<h3>Dirham marocain : cash bienvenu, carte en hausse</h3>
<p>Dans les grandes villes (Casablanca, Rabat, Marrakech), la carte progresse vite. Mais dans les souks, taxis et petits riads, le cash reste roi. Anticipez un mélange de coupures pour négocier et payer rapidement.</p>

<h3>Sur place</h3>
<ul>
  <li>Changez une partie en petites coupures pour les pourboires.</li>
  <li>Les distributeurs peuvent limiter le retrait par opération ; prévoyez un peu de marge en espèces.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ MAD</h3>
<p>Calculez votre budget voyage avec nos blocs « Vente / Achat ». Pour éviter de garder des billets au retour, passez nous voir avant l’aéroport – nos taux sont affichés en temps réel.</p>
HTML);
            case 'XOF': return $mk(<<<'HTML'
<h3>Franc CFA (UEMOA) : une zone, plusieurs pays</h3>
<p>Le XOF circule dans 8 pays d’Afrique de l’Ouest. Le cash est omniprésent, même si la carte progresse dans les capitales et hôtels. Pour un séjour multi-pays, prévoyez des coupures variées.</p>

<h3>Conseils terrain</h3>
<ul>
  <li>Gardez les billets en bon état ; les très abîmés peuvent être refusés.</li>
  <li>Transportez l’espèce de manière discrète (pochette intérieure, répartition).</li>
  <li>Changez par paliers plutôt que tout d’un coup si votre itinéraire est flexible.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ XOF</h3>
<p>Utilisez le convertisseur : prévoyez une enveloppe « démarrage » (taxis, cartes SIM, pourboires) + une réserve que vous pourrez changer au besoin.</p>
HTML);
            case 'INR': return $mk(<<<'HTML'
<h3>Roupie indienne : cash utile, QR omniprésent</h3>
<p>En Inde, le cash reste très utile, mais les paiements par QR (UPI) sont partout dans les villes. En tant que voyageur, gardez des roupies pour taxis, petits restos et pourboires.</p>

<h3>Préparer ses dépenses</h3>
<ul>
  <li>Demandez des petites coupures : payer 120 ₹ avec un billet de 2 000 ₹ complique la monnaie.</li>
  <li>Les distributeurs peuvent avoir des files d’attente ; gardez une marge en cash.</li>
  <li>Les trains et certains guichets préfèrent l’espèce.</li>
</ul>

<hr>

<h3>Changer EUR ⇄ INR</h3>
<p>Simulez votre budget dans la section « Vente / Achat ». Au retour, rapportez des billets propres : c’est plus rapide pour le rachat.</p>
HTML);
            default: return $mk(<<<HTML
<h3>Comprendre le change EUR ⇄ {$code}</h3>
<p>Au-dessus, deux blocs : <em>Vente</em> (vous partez avec des <strong>{$code}</strong>) et <em>Achat</em> (vous nous revendez des <strong>{$code}</strong>). Les deux taux sont différents car ils intègrent les coûts de marché et d’exploitation : c’est le <em>spread</em> de change.</p>

<h3>Préparer votre budget</h3>
<p>Estimez vos dépenses quotidiennes (transport, repas, pourboires) et convertissez avec l’outil pour choisir vos coupures. Sur place, combinez carte + espèces pour éviter les frais et rester flexible.</p>

<h3>Conseils pratiques</h3>
<ul>
  <li>Prévoyez quelques petites coupures pour les taxis et petites boutiques.</li>
  <li>Évitez les billets abîmés ou scotchés : ils sont parfois refusés.</li>
  <li>Gardez votre pièce d’identité pour les montants élevés.</li>
</ul>

<blockquote>
  Astuce : si vous revenez avec un solde en {$code}, nous pouvons le racheter (selon l’état et la série des billets).
</blockquote>
HTML);
        }
    }
}

