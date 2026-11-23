<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Lê do APK (Android) o pacote, o versionCode e o versionName gravados no AndroidManifest.xml compilado (formato
 * binário "AXML" do Android, dentro do zip). Usado ao publicar uma versão do aplicativo no portal: a versão vem do
 * próprio arquivo, sem digitar.
 */
final class LeitorApk
{
    /**
     * @return array{pacote: string, codigo: int, nome: string}
     *
     * @throws RuntimeException arquivo que não é um APK válido
     */
    public static function ler(string $caminho): array
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('O arquivo não é um APK (zip) válido.');
        }
        $manifesto = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if ($manifesto === false || strlen($manifesto) < 8) {
            throw new RuntimeException('O APK não tem o AndroidManifest.xml.');
        }

        return self::doManifesto($manifesto);
    }

    /** @return array{pacote: string, codigo: int, nome: string} */
    public static function doManifesto(string $b): array
    {
        if (self::u16($b, 0) !== 0x0003) {
            throw new RuntimeException('AndroidManifest.xml em formato desconhecido.');
        }
        $strings = [];
        $pos = self::u16($b, 2); // depois do cabeçalho do documento
        $tamanho = strlen($b);
        while ($pos + 8 <= $tamanho) {
            $tipo = self::u16($b, $pos);
            $cabecalho = self::u16($b, $pos + 2);
            $tamanhoBloco = self::u32($b, $pos + 4);
            if ($tamanhoBloco < 8) {
                break;
            }
            if ($tipo === 0x0001) {
                $strings = self::strings($b, $pos);
            } elseif ($tipo === 0x0102) {
                // Primeiro elemento = <manifest>: atributos package, versionCode e versionName.
                $inicioAttr = $pos + $cabecalho + self::u16($b, $pos + $cabecalho + 8);
                $tamAttr = self::u16($b, $pos + $cabecalho + 10);
                $qtd = self::u16($b, $pos + $cabecalho + 12);
                $achados = [];
                for ($i = 0; $i < $qtd; $i++) {
                    $a = $inicioAttr + $i * $tamAttr;
                    $nome = $strings[self::u32($b, $a + 4)] ?? '';
                    $bruto = self::u32($b, $a + 8);
                    $tipoDado = ord($b[$a + 15]);
                    $dado = self::u32($b, $a + 16);
                    $achados[$nome] = $tipoDado === 0x03 || $bruto !== 0xFFFFFFFF
                        ? ($strings[$bruto !== 0xFFFFFFFF ? $bruto : $dado] ?? '')
                        : $dado;
                }
                if (! isset($achados['package'], $achados['versionCode'])) {
                    throw new RuntimeException('O APK não informa o pacote e a versão.');
                }

                return [
                    'pacote' => (string) $achados['package'],
                    'codigo' => (int) $achados['versionCode'],
                    'nome' => (string) ($achados['versionName'] ?? ''),
                ];
            }
            $pos += $tamanhoBloco;
        }
        throw new RuntimeException('O AndroidManifest.xml não tem o elemento <manifest>.');
    }

    /** Tabela de textos (string pool), UTF-8 ou UTF-16. @return list<string> */
    private static function strings(string $b, int $inicio): array
    {
        $qtd = self::u32($b, $inicio + 8);
        $utf8 = (self::u32($b, $inicio + 16) & 0x100) !== 0;
        $base = $inicio + self::u32($b, $inicio + 20);
        $offsets = $inicio + self::u16($b, $inicio + 2);
        $lista = [];
        for ($i = 0; $i < $qtd; $i++) {
            $p = $base + self::u32($b, $offsets + $i * 4);
            if ($utf8) {
                $p += (ord($b[$p]) & 0x80) ? 2 : 1;           // tamanho em caracteres
                $bytes = ord($b[$p]);
                if ($bytes & 0x80) {
                    $bytes = (($bytes & 0x7F) << 8) | ord($b[$p + 1]);
                    $p += 2;
                } else {
                    $p += 1;
                }
                $lista[] = substr($b, $p, $bytes);
            } else {
                $chars = self::u16($b, $p);
                $p += 2;
                if ($chars & 0x8000) {
                    $chars = (($chars & 0x7FFF) << 16) | self::u16($b, $p);
                    $p += 2;
                }
                $lista[] = mb_convert_encoding(substr($b, $p, $chars * 2), 'UTF-8', 'UTF-16LE');
            }
        }

        return $lista;
    }

    private static function u16(string $b, int $p): int
    {
        return unpack('v', $b, $p)[1];
    }

    private static function u32(string $b, int $p): int
    {
        return unpack('V', $b, $p)[1];
    }
}
