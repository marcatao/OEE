<?php

namespace Database\Seeders;

use App\Models\MotivoParada;
use Illuminate\Database\Seeder;

/**
 * Dados iniciais: motivos de parada mais comuns, ligados às seis grandes perdas do OEE.
 * Pode rodar mais de uma vez (não duplica). Uso: php artisan db:seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $motivos = [
            // código, descrição, categoria (grande perda), planejada
            ['PLAN-REF', 'Refeição / intervalo', 'Parada planejada', true],
            ['PLAN-PREV', 'Manutenção preventiva programada', 'Parada planejada', true],
            ['PLAN-SEMPROG', 'Sem programação de produção', 'Parada planejada', true],
            ['QUEB-MEC', 'Quebra mecânica', 'Quebras e falhas', false],
            ['QUEB-ELE', 'Falha elétrica / eletrônica', 'Quebras e falhas', false],
            ['SETUP', 'Setup / troca de produto', 'Setup e ajustes', false],
            ['AJUSTE', 'Ajuste / regulagem', 'Setup e ajustes', false],
            ['FALTA-MAT', 'Falta de material', 'Pequenas paradas', false],
            ['FALTA-OPER', 'Falta de operador', 'Pequenas paradas', false],
            ['MICRO', 'Pequena parada (não identificada)', 'Pequenas paradas', false],
            ['QUALID', 'Parada por problema de qualidade', 'Refugo e retrabalho', false],
        ];

        foreach ($motivos as [$codigo, $descricao, $categoria, $planejada]) {
            MotivoParada::firstOrCreate(
                ['codigo' => $codigo],
                ['descricao' => $descricao, 'categoria' => $categoria, 'planejada' => $planejada],
            );
        }
    }
}
