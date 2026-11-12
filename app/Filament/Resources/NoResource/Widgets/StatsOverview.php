<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Tool;
use App\Models\Loan;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Stok Alat', Tool::sum('stock')),
            Stat::make('Menunggu', Loan::where('status', 'menunggu')->count()),
            Stat::make('Dipinjam', Loan::where('status', 'dipinjam')->count()),
        ];
    }
}