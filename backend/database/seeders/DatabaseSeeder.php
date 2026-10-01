<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $user = User::updateOrCreate(
            ['email' => 'demo@fcontrol.local'],
            ['name' => 'Usuário Demonstração', 'password' => 'FControl@12345'],
        );
        foreach (['Moradia', 'Alimentação', 'Transporte', 'Saúde', 'Educação', 'Lazer', 'Assinaturas', 'Compras', 'Impostos', 'Outros'] as $name) {
            $user->categories()->firstOrCreate(['name' => $name, 'type' => 'expense'], ['is_default' => true]);
        }
        foreach (['Salário', 'Freelancer', 'Rendimentos', 'Reembolso', 'Venda', 'Outros'] as $name) {
            $user->categories()->firstOrCreate(['name' => $name, 'type' => 'income'], ['is_default' => true]);
        }
    }
}
