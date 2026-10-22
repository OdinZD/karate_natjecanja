<?php

namespace App\Support;

class RoundLabel
{
    /**
     * Name of a round of the main bracket: the final, the semifinals, the quarterfinals, otherwise "Round n".
     */
    public static function for(int $round, int $finalRound): string
    {
        return match (true) {
            $round === $finalRound => __('Final'),
            $round === $finalRound - 1 => __('Semifinals'),
            $round === $finalRound - 2 => __('Quarterfinals'),
            default => __('Round :number', ['number' => $round]),
        };
    }
}
