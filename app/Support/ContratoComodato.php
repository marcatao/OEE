<?php

namespace App\Support;

/**
 * Texto do termo de responsabilidade do Inventário de TI: o CONTRATO PARTICULAR DE COMODATO da empresa (modelo do
 * usuário em 25/11/2026). Fica aqui (e não no config) para não depender do cache de configuração do servidor.
 * Cláusula com 'telefonia' = só para celular/tablet (TIPOS_TELEFONIA) e linha/chip.
 */
final class ContratoComodato
{
    public const TITULO = 'Contrato particular de comodato';

    public const CIDADE = 'São Bernardo do Campo';

    public const COMODANTE = [
        'razao_social' => 'ALUMBRA PRODUTOS ELÉTRICOS E ELETRÔNICOS LTDA.',
        'nome_curto' => 'ALUMBRA PROD. ELÉT. E ELETR. LTDA.',
        'qualificacao' => 'pessoa jurídica de direito privado, sediada na Rua Guimarães Rosa nº 450, Jardim Continental, São Bernardo do Campo – SP, inscrita no CNPJ/MF sob o nº 59.114.777/0001-20',
    ];

    public const TIPOS_TELEFONIA = ['Celular', 'Tablet'];

    public const CLAUSULAS = [
        ['telefonia' => true, 'texto' => 'O COMODATÁRIO deve mantê-lo em funcionamento (ativo) a serviço da empresa, bem como fazer e receber ligações com qualquer Claro do grupo Alumbra durante o horário estabelecido em seu Contrato Individual de Trabalho.'],
        ['texto' => 'O uso do equipamento é para fins profissionais e está restrito ao horário de seu Contrato Individual de Trabalho.'],
        ['texto' => 'O COMODATO é por prazo indeterminado, cessando, de pleno direito, quando não mais for de interesse de qualquer das partes.'],
        ['texto' => 'O COMODATÁRIO se obriga a devolvê-lo(a) em condições de perfeito funcionamento, devendo cuidá-lo como se dono fosse.'],
        ['texto' => 'Ao COMODATÁRIO cabe conservá-lo, cuidando do aparelho, de sua manutenção, limpeza e outras que se fizerem necessárias.'],
        ['texto' => 'É vedado seu empréstimo a terceiros.'],
        ['texto' => 'Para efeito deste contrato o referido(a) tem o preço de mercado.'],
        ['texto' => 'O COMODATÁRIO deve comunicar imediatamente o COMODANTE a ocorrência de perda, dano, furto ou roubo do equipamento, e se for o caso de perda, furto ou roubo, providenciar a feitura do B.O.'],
        ['texto' => 'Se o COMODATÁRIO o utilizar para fins particulares pagará pelo uso dele.'],
        ['telefonia' => true, 'texto' => 'O COMODATÁRIO fica extremamente proibido de remover o chip e/ou trocar a carcaça do aparelho, tendo em vista penalidades aplicadas pela operadora.'],
        ['texto' => 'A Política de Segurança da Informação da empresa encontra-se disponível na intranet da empresa, a qual deverá ser lida e cumprida, no que for aplicável.'],
    ];

    public const FECHAMENTO = 'Para firmeza e prova de assim haverem contratado, firmam o presente instrumento em duas vias de igual teor, na presença de testemunhas que tudo assistiram e que de tudo conhecimento tiveram.';
}
