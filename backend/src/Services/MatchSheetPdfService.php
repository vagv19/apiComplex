<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class MatchSheetPdfService
{
    public function createDocument(array $match, string $logoDataUri): Dompdf
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml($this->buildHtml($match, $logoDataUri), 'UTF-8');
        $dompdf->render();
        return $dompdf;
    }

    private function buildHtml(array $match, string $logoDataUri): string
    {
        $homeTable = $this->renderTeamTable($match['home_team']);
        $awayTable = $this->renderTeamTable($match['away_team']);
        $score = $match['home_score'] === null || $match['away_score'] === null
            ? '— : —'
            : $this->escape($match['home_score']) . ' : ' . $this->escape($match['away_score']);
        $matchDate = 'Sin fecha';
        if ($match['match_date'] !== null && $match['match_date'] !== '') {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $match['match_date']);
            if ($parsedDate !== false) {
                $matchDate = $parsedDate->format('d/m/Y');
            }
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<style>
                @page { size: letter portrait; margin: 10mm 6mm 12mm; }
                body { font-family: "DejaVu Sans", sans-serif; color: #111; font-size: 8px; }
                table { border-collapse: collapse; }
                .header { width: 100%; margin-bottom: 8px; }
                .header td { vertical-align: middle; text-align: center; }
                .logo-cell { width: 17%; }
                .title-cell { width: 66%; }
                .logo { width: 58px; height: 58px; object-fit: contain; }
                h1 { font-size: 17px; margin: 0 0 4px; }
                .league { font-size: 11px; font-weight: bold; }
                .metadata { width: 100%; margin: 7px 0; }
                .metadata td { border: 1px solid #333; padding: 4px 5px; width: 50%; }
                .label { font-weight: bold; }
                .score { text-align: center; font-size: 14px; font-weight: bold; padding: 3px 0 6px; }
                .teams { width: 100%; table-layout: fixed; }
                .teams > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
                .team-title { margin: 0; padding: 5px 2px; font-size: 9px; font-weight: bold; text-align: center; }
                .team-table { width: 100%; table-layout: fixed; }
                .team-table th, .team-table td { border: 1px solid #333; text-align: center; }
                .team-table th:not(.team-title), .team-table td { padding: 1px; }
                .team-table th { background: #ececec; font-size: 6.5px; white-space: nowrap; }
                .team-table td { height: 15px; font-size: 7px; }
                .team-table td:nth-child(2) { text-align: left; padding-left: 1px; }
                .team-summaries { width: 100%; table-layout: fixed; }
                .team-summaries td { width: 50%; padding: 0 12px; }
                .team-summary { width: 100%; margin-top: 20px; text-align: right; font-size: 10px; }
                .signatures { width: 100%; margin-top: 20px; page-break-inside: avoid; }
                .signatures td { width: 50%; text-align: center; padding: 0 12px; }
                .signature-line { border-top: 1px solid #111; height: 4px; }
                .signature-label { font-size: 8px; font-weight: bold; }
            </style></head><body>'
            . '<table class="header"><tr>'
            . '<td class="logo-cell"><img class="logo" src="' . $this->escape($logoDataUri) . '" alt="Logotipo institucional"></td>'
            . '<td class="title-cell"><h1>CÉDULA ARBITRAL</h1><div class="league">' . $this->escape($match['league_name']) . '</div></td>'
            . '<td class="logo-cell"><img class="logo" src="' . $this->escape($logoDataUri) . '" alt="Logotipo institucional"></td>'
            . '</tr></table>'
            . '<table class="metadata"><tr>'
            . '<td><span class="label">Torneo:</span> ' . $this->escape($match['tournament_name']) . '</td>'
            . '<td><span class="label">Categoría:</span> ' . $this->escape($match['category_name']) . '</td>'
            . '</tr><tr>'
            . '<td><span class="label">Jornada:</span> ' . $this->escape($match['round_name']) . '</td>'
            . '<td><span class="label">Fase:</span> ' . $this->escape($match['stage_name']) . '</td>'
            . '</tr><tr>'
            . '<td><span class="label">Campo:</span> ' . $this->escape($match['venue'] ?: 'La Pasion') . '</td>'
            . '<td><span class="label">Hora:</span> ' . $this->escape($match['kickoff_time']) . '</td>'
            . '</tr><tr>'
            . '<td><span class="label">Día:</span> ' . $this->escape($match['match_day']) . '</td>'
            . '<td><span class="label">Fecha:</span> ' . $this->escape($matchDate) . '</td>'
            . '</tr></table>'
            . '<table class="teams"><tr><td>' . $homeTable . '</td><td>' . $awayTable . '</td></tr></table>'
            . '<table class="team-summaries"><tr>'
            . '<td><div class="team-summary">(     )__________</div></td>'
            . '<td><div class="team-summary">(     )__________</div></td>'
            . '</tr></table>'
            . '<table class="signatures"><tr>'
            . '<td><div class="signature-line"></div><div class="signature-label">Firma del capitán: ' . $this->escape($match['home_team']['name']) . '</div></td>'
            . '<td><div class="signature-line"></div><div class="signature-label">Firma del capitán: ' . $this->escape($match['away_team']['name']) . '</div></td>'
            . '</tr></table></body></html>';
    }

    private function renderTeamTable(array $team): string
    {
        $players = $team['players'];
        $rowCount = max(18, count($players));
        $rows = '';

        for ($index = 0; $index < $rowCount; $index++) {
            $player = $players[$index] ?? null;
            $rows .= '<tr>'
                . '<td class="number">' . ($player === null || $player['jersey_number'] === null ? '&nbsp;' : $this->escape($player['jersey_number'])) . '</td>'
                . '<td class="player-name">' . ($player === null ? '&nbsp;' : $this->escape($player['name'])) . '</td>'
                . '<td>' . ($player === null ? '&nbsp;' : $this->escape($player['goals'])) . '</td>'
                . '<td>' . ($player === null ? '&nbsp;' : $this->escape($player['cautions'])) . '</td>'
                . '<td>' . ($player === null ? '&nbsp;' : $this->escape($player['expulsions'])) . '</td>'
                . '</tr>';
        }

        return '<div class="team-title">' . $this->escape($team['name']) . '</div>'
            . '<table class="team-table">'
            . '<thead><tr>'
            . '<th style="width: 10.5263%;">#</th>'
            . '<th style="width: 57.8948%;">NOMBRE DE JUGADOR</th>'
            . '<th style="width: 10.5263%;">GOL</th><th style="width: 10.5263%;">AMO</th><th style="width: 10.5263%;">EXP</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
