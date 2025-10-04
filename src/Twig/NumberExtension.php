<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // usage: {{ value|pretty_rate }} ou {{ value|pretty_rate(6) }}
            new TwigFilter('pretty_rate', [$this, 'prettyRate']),
        ];
    }

    /**
     * Simplifie l'affichage d'un taux (supprime zéros inutiles).
     *
     * @param float|int|null $value
     * @param int $decimals max décimales (par défaut 6)
     */
    public function prettyRate($value, int $decimals = 6): string
    {
        if ($value === null || !is_numeric($value)) {
            return '—';
        }
        $s = number_format((float)$value, $decimals, '.', '');
        $s = rtrim($s, '0');
        $s = rtrim($s, '.');
        return $s === '' ? '0' : $s;
    }
}

