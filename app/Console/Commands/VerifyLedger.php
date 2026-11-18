<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Enums\TransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Money;
use Illuminate\Console\Command;

class VerifyLedger extends Command
{
    protected $signature = 'pixelforge:verify-ledger';

    protected $description = 'Check that cached balances match the append-only ledger and that no generation is double-charged';

    public function handle(): int
    {
        $problems = 0;

        $problems += $this->checkCachedBalances();
        $problems += $this->checkSingleChargePerGeneration();
        $problems += $this->checkNoHoldsOnSettledGenerations();
        $problems += $this->checkFailedGenerationsAreFree();

        if ($problems > 0) {
            $this->error("{$problems} ledger discrepancy/discrepancies found.");

            return self::FAILURE;
        }

        $this->info('Ledger verified: cached balances match the ledger and every generation is charged at most once.');

        return self::SUCCESS;
    }

    private function checkCachedBalances(): int
    {
        $problems = 0;

        User::query()->with('creditTransactions')->each(function (User $user) use (&$problems): void {
            $folded = $user->foldLedger();

            if ($folded['balance']->nanodollars !== $user->balance_nanodollars) {
                $this->error(sprintf(
                    'User %d: cached balance %s but ledger folds to %s.',
                    $user->getKey(),
                    $user->balance()->toUsdString(),
                    $folded['balance']->toUsdString(),
                ));
                $problems++;
            }

            if ($folded['held']->nanodollars !== $user->held_nanodollars) {
                $this->error(sprintf(
                    'User %d: cached hold %s but ledger folds to %s.',
                    $user->getKey(),
                    $user->held()->toUsdString(),
                    $folded['held']->toUsdString(),
                ));
                $problems++;
            }
        });

        return $problems;
    }

    private function checkSingleChargePerGeneration(): int
    {
        $offenders = CreditTransaction::query()
            ->select('generation_id')
            ->selectRaw('COUNT(*) as charge_count')
            ->where('type', TransactionType::Charge)
            ->whereNotNull('generation_id')
            ->groupBy('generation_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($offenders as $row) {
            $this->error("Generation {$row->generation_id} has {$row->charge_count} charges.");
        }

        return $offenders->count();
    }

    private function checkNoHoldsOnSettledGenerations(): int
    {
        $offenders = Generation::query()
            ->settled()
            ->where('hold_nanodollars', '!=', 0)
            ->get();

        foreach ($offenders as $generation) {
            $this->error(sprintf(
                'Generation %s is %s but still holds %s in reserved credit.',
                $generation->getKey(),
                $generation->status->value,
                $generation->hold()->toUsdString(),
            ));
        }

        return $offenders->count();
    }

    private function checkFailedGenerationsAreFree(): int
    {
        $offenders = Generation::query()
            ->whereIn('status', [GenerationStatus::Failed, GenerationStatus::Rejected])
            ->where('charged_nanodollars', '>', 0)
            ->get();

        foreach ($offenders as $generation) {
            $this->error(sprintf(
                'Generation %s is %s but was charged %s.',
                $generation->getKey(),
                $generation->status->value,
                Money::fromNanodollars($generation->charged_nanodollars)->toUsdString(),
            ));
        }

        return $offenders->count();
    }
}
