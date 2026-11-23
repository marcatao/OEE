<?php

namespace App\Services\Aplicativo;

use App\Models\VersaoAplicativo;
use App\Support\LeitorApk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Atualização do aplicativo pelo portal: o administrador publica o APK (a versão é lida do próprio arquivo), o app
 * pergunta a versão mais nova (/api/app/versao), baixa o APK pelo portal (/api/app/versao/apk) e pede a instalação.
 * O APK fica no disco "local" (storage/app/private/aplicativo), nunca na pasta pública.
 */
class VersoesAplicativo
{
    public const PASTA = 'aplicativo';

    public function publicar(UploadedFile $arquivo, bool $obrigatoria, ?string $observacao): VersaoAplicativo
    {
        try {
            $apk = LeitorApk::ler($arquivo->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['apk' => $e->getMessage()]);
        }
        $pacote = (string) config('oee.app.pacote', 'br.com.alumbra.oee');
        if ($apk['pacote'] !== $pacote) {
            throw ValidationException::withMessages(['apk' => "Este APK é de outro aplicativo ({$apk['pacote']}), não do {$pacote}."]);
        }
        if ($apk['codigo'] <= 0 || $apk['nome'] === '') {
            throw ValidationException::withMessages(['apk' => 'O APK não informa a versão.']);
        }
        $existente = VersaoAplicativo::withoutGlobalScopes()->where('codigo', $apk['codigo'])->first();
        if ($existente) {
            throw ValidationException::withMessages(['apk' => "A versão {$existente->nome} (build {$existente->codigo}) já foi publicada."]);
        }

        $versao = new VersaoAplicativo(['codigo' => $apk['codigo'], 'nome' => $apk['nome']]);
        $caminho = $arquivo->storeAs(self::PASTA, $versao->nomeArquivo(), 'local');
        if ($caminho === false) {
            throw ValidationException::withMessages(['apk' => 'Não foi possível gravar o arquivo no servidor.']);
        }
        $versao->fill([
            'arquivo' => $caminho,
            'tamanho' => (int) $arquivo->getSize(),
            'sha256' => hash_file('sha256', $arquivo->getRealPath()),
            'obrigatoria' => $obrigatoria,
            'ativo' => true,
            'observacao' => $observacao !== null && trim($observacao) !== '' ? trim($observacao) : null,
            'unidade' => 'ambos',
        ])->save();

        return $versao;
    }

    /** A versão mais nova oferecida ao app (ativa e com o arquivo no servidor). */
    public function atual(): ?VersaoAplicativo
    {
        return VersaoAplicativo::withoutGlobalScopes()->ativas()->orderByDesc('codigo')->get()
            ->first(fn (VersaoAplicativo $v) => Storage::disk('local')->exists($v->arquivo));
    }

    /** Build mínimo exigido: a maior versão ativa marcada como obrigatória (0 = nenhuma). */
    public function minimaObrigatoria(): int
    {
        return (int) VersaoAplicativo::withoutGlobalScopes()->ativas()->where('obrigatoria', true)->max('codigo');
    }

    /** Resposta de /api/app/versao para o build instalado no celular. */
    public function paraOApp(?int $instalado): array
    {
        $atual = $this->atual();
        $minima = min($this->minimaObrigatoria(), $atual?->codigo ?? 0);

        return [
            'atual' => $atual ? [
                'codigo' => $atual->codigo,
                'nome' => $atual->nome,
                'tamanho' => $atual->tamanho,
                'sha256' => $atual->sha256,
                'observacao' => $atual->observacao,
                'publicada_em' => $atual->criado_em?->toIso8601String(),
            ] : null,
            'minima' => $minima,
            'tem_nova' => $atual !== null && $instalado !== null && $atual->codigo > $instalado,
            'obrigatoria' => $instalado !== null && $instalado < $minima,
        ];
    }
}
