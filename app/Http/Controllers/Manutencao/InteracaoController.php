<?php

namespace App\Http\Controllers\Manutencao;

use App\Http\Controllers\Controller;
use App\Models\Manutencao\AnexoInteracaoOs;
use App\Models\Manutencao\InteracaoOs;
use App\Models\Manutencao\OrdemServico;
use App\Support\AcessoOs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interações da OS: conversa entre todos os envolvidos (quem vê a OS), com texto, links e anexos (documentos e prints).
 * Os arquivos ficam no disco privado "local" (storage/app/private/os-anexos/{os}) e só são entregues a quem vê a OS.
 */
class InteracaoController extends Controller
{
    /** Até quantos arquivos por comentário. */
    public const MAX_ARQUIVOS = 5;

    /** Tamanho máximo por arquivo (KB): o menor entre 20 MB e o limite de upload do PHP do servidor. */
    public static function limiteKb(): int
    {
        $bytes = function (string $valor): int {
            $valor = trim($valor);
            $n = (int) $valor;

            return match (strtolower(substr($valor, -1))) {
                'g' => $n * 1024 * 1024 * 1024, 'm' => $n * 1024 * 1024, 'k' => $n * 1024, default => $n,
            };
        };
        $php = min(array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]) ?: [20 * 1048576]);

        return (int) floor(min(20 * 1048576, $php) / 1024);
    }

    public function store(Request $request, int $ordem): RedirectResponse
    {
        $os = AcessoOs::filtrar(OrdemServico::query(), $request->user())->findOrFail($ordem);
        AcessoOs::exigirAberta($os);
        $limite = self::limiteKb();
        $dados = $request->validate([
            'texto' => ['nullable', 'string', 'max:4000'],
            'anexos' => ['nullable', 'array', 'max:'.self::MAX_ARQUIVOS],
            'anexos.*' => ['file', 'max:'.$limite,
                'mimes:jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z,dwg,dxf,mp4,mov'],
        ], [
            'anexos.max' => 'Envie até '.self::MAX_ARQUIVOS.' arquivos por comentário.',
            'anexos.*.max' => 'Cada arquivo pode ter até '.number_format($limite / 1024, 1, ',', '.').' MB.',
            'anexos.*.mimes' => 'Tipo de arquivo não aceito. Use imagens, PDF, Office, texto, compactados, desenhos ou vídeos.',
            'anexos.*.uploaded' => 'O arquivo não chegou ao servidor (passou do limite de tamanho?).',
        ]);
        $arquivos = $request->file('anexos', []);
        if (trim((string) ($dados['texto'] ?? '')) === '' && ! $arquivos) {
            throw ValidationException::withMessages(['texto' => 'Escreva um comentário ou anexe um arquivo.']);
        }

        $gravados = [];
        try {
            DB::transaction(function () use ($request, $os, $dados, $arquivos, &$gravados) {
                $interacao = InteracaoOs::create([
                    'ordem_servico_id' => $os->id,
                    'usuario_id' => $request->user()->id,
                    'texto' => trim((string) ($dados['texto'] ?? '')) ?: null,
                ]);
                foreach ($arquivos as $arquivo) {
                    $nome = mb_substr($arquivo->getClientOriginalName() ?: 'arquivo', 0, 200);
                    $extensao = strtolower($arquivo->getClientOriginalExtension() ?: $arquivo->extension() ?: 'bin');
                    $caminho = $arquivo->storeAs("os-anexos/{$os->id}", Str::uuid()->toString().'.'.$extensao, 'local');
                    $gravados[] = $caminho;
                    AnexoInteracaoOs::create([
                        'interacao_id' => $interacao->id, 'nome' => $nome, 'caminho' => $caminho,
                        'mime' => $arquivo->getMimeType(), 'tamanho' => (int) $arquivo->getSize(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($gravados); // não deixa arquivo solto se o banco falhar

            throw $e;
        }

        return redirect()->to(route('os.ordens.show', $os).'#interacoes')->with('ok', 'Comentário enviado.');
    }

    /** Entrega o anexo (imagem e PDF abrem no navegador; o resto baixa) só para quem vê a OS. */
    public function anexo(Request $request, int $anexo): Response
    {
        $a = AnexoInteracaoOs::with('interacao')->findOrFail($anexo);
        AcessoOs::filtrar(OrdemServico::query(), $request->user())->findOrFail($a->interacao->ordem_servico_id);
        abort_unless(Storage::disk('local')->exists($a->caminho), 404, 'Arquivo não encontrado neste servidor.');
        $caminho = Storage::disk('local')->path($a->caminho);
        $abreNoNavegador = $a->ehImagem() || $a->mime === 'application/pdf';

        $resposta = response()->file($caminho, ['Content-Type' => $abreNoNavegador ? $a->mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
        $nome = str_replace(['/', '\\'], '-', $a->nome);
        $nomeAscii = preg_replace('/[^A-Za-z0-9._ -]/', '_', Str::ascii($nome)) ?: 'anexo';
        $resposta->setContentDisposition($abreNoNavegador ? 'inline' : 'attachment', $nome, $nomeAscii);

        return $resposta;
    }
}
