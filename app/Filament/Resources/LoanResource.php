<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoanResource\Pages;
use App\Filament\Resources\LoanResource\RelationManagers;
use App\Models\Loan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class LoanResource extends Resource
{
    protected static ?string $model = Loan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()?->isPeminjam()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        if (auth()->user()?->isAdmin() || in_array($action, ['viewAny', 'create'])) {
            return Response::allow();
        }

        return Response::deny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->hidden(fn () => auth()->user()?->isPeminjam())
                    ->default(fn () => auth()->id())
                    ->dehydrated(true)
                    ->required(),
                Forms\Components\Select::make('tool_id')
                    ->relationship('tool', 'name')
                    ->required(),
                Forms\Components\TextInput::make('qty')
                    ->required()
                    ->numeric()
                    ->default(1),
                Forms\Components\DatePicker::make('loan_date')
                    ->default(now())
                    ->required(),
                Forms\Components\DatePicker::make('due_date')
                    ->required(),
                Forms\Components\DatePicker::make('returned_at'),
                Forms\Components\TextInput::make('status')
                    ->required()
                    ->maxLength(255)
                    ->default('menunggu'),
                Forms\Components\TextInput::make('fine')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tool.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('qty')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('loan_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('returned_at')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'menunggu' => 'warning',
                        'dipinjam' => 'info',
                        'ditolak' => 'danger',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('fine')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\Action::make('cetak')
                    ->label('Cetak Laporan')
                    ->icon('heroicon-o-printer')
                    ->url('/laporan')
                    ->openUrlInNewTab()
                    ->visible(fn () => !auth()->user()?->isPeminjam()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('setujui')
                    ->label('Setujui')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Loan $record) => $record->status === 'menunggu' && !auth()->user()?->isPeminjam())
                    ->action(function (Loan $record) {
                        if ($record->tool->stock < $record->qty) {
                            \Filament\Notifications\Notification::make()
                                ->title('Stok tidak cukup')
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->tool->decrement('stock', $record->qty);
                        $record->update(['status' => 'dipinjam']);

                        if (class_exists(\App\Models\ActivityLog::class)) {
                            \App\Models\ActivityLog::catat('Menyetujui peminjaman #' . $record->id);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Peminjaman disetujui')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('tolak')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Loan $record) => $record->status === 'menunggu' && !auth()->user()?->isPeminjam())
                    ->action(function (Loan $record) {
                        $record->update(['status' => 'ditolak']);

                        if (class_exists(\App\Models\ActivityLog::class)) {
                            \App\Models\ActivityLog::catat('Menolak peminjaman #' . $record->id);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Peminjaman ditolak')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('kembalikan')
                    ->label('Kembalikan')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (Loan $record) => $record->status === 'dipinjam' && !auth()->user()?->isPeminjam())
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('returned_at')
                            ->default(now())
                            ->required(),
                    ])
                    ->action(function (Loan $record, array $data) {
                        $telat = (int) \Illuminate\Support\Carbon::parse($record->due_date)->diffInDays(\Illuminate\Support\Carbon::parse($data['returned_at']), false);
                        
                        $record->update([
                            'returned_at' => $data['returned_at'],
                            'status' => 'dikembalikan',
                            'fine' => max(0, $telat) * 5000,
                        ]);

                        $record->tool->increment('stock', $record->qty);

                        if (class_exists(\App\Models\ActivityLog::class)) {
                            \App\Models\ActivityLog::catat('Pengembalian peminjaman #' . $record->id);
                        }

                        \Filament\Notifications\Notification::make()
                            ->title('Alat sudah dikembalikan')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoans::route('/'),
            'create' => Pages\CreateLoan::route('/create'),
            'edit' => Pages\EditLoan::route('/{record}/edit'),
        ];
    }
}