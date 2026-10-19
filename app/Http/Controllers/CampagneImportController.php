<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Models\Campaign;
use App\Services\Publication\ImportCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Import d'une campagne en CSV, en deux temps : on dépose le fichier et on
 * voit ce qui serait créé (aperçu, erreurs, fichiers attendus), puis on
 * confirme. Entre les deux, le CSV est gardé en session et relu à la
 * confirmation : ce qui est importé est exactement ce qui a été montré.
 */
class CampagneImportController extends Controller
{
    use ResolvesClient;

    public function __construct(private ImportCsv $import) {}

    public function gabarit()
    {
        return response($this->import->gabarit(), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="gabarit-campagne-studiomachine.csv"',
        ]);
    }

    public function formulaire(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        return view('publication.campagne-import', ['campaign' => $campaign, 'analyse' => null, 'jeton' => null]);
    }

    public function apercu(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        $request->validate(['fichier' => ['required', 'file', 'max:2048', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel']], [
            'fichier.mimetypes' => 'Le fichier doit être un CSV (dans Excel ou Numbers : Exporter → CSV).',
        ]);

        $contenu = (string) file_get_contents($request->file('fichier')->getRealPath());
        $analyse = $this->import->analyser($campaign->client, $contenu);

        $jeton = null;
        if (! $analyse['erreurs'] && $analyse['publications']) {
            $jeton = Str::random(24);
            $request->session()->put("import_csv.{$jeton}", ['campaign_id' => $campaign->id, 'contenu' => $contenu]);
        }

        return view('publication.campagne-import', compact('campaign', 'analyse', 'jeton'));
    }

    public function confirmer(Request $request, Campaign $campaign)
    {
        $this->authorizeClient($request, $campaign->client_id);

        $d = $request->validate(['jeton' => ['required', 'string']]);
        $enAttente = $request->session()->pull("import_csv.{$d['jeton']}");

        if (! $enAttente || $enAttente['campaign_id'] !== $campaign->id) {
            return redirect()->route('campaigns.import', $campaign)->with('ok', 'L’aperçu a expiré : déposez le fichier de nouveau.');
        }

        $analyse = $this->import->analyser($campaign->client, $enAttente['contenu']);
        if ($analyse['erreurs']) {
            return view('publication.campagne-import', ['campaign' => $campaign, 'analyse' => $analyse, 'jeton' => null]);
        }

        $bilan = $this->import->importer($campaign->client, $campaign, $analyse['publications'], $request->user());

        $morceaux = array_filter([
            $bilan['creees'] ? "{$bilan['creees']} créée" . ($bilan['creees'] > 1 ? 's' : '') : null,
            $bilan['mises_a_jour'] ? "{$bilan['mises_a_jour']} mise" . ($bilan['mises_a_jour'] > 1 ? 's' : '') . ' à jour' : null,
            $bilan['ignorees'] ? "{$bilan['ignorees']} déjà partie" . ($bilan['ignorees'] > 1 ? 's' : '') . ', laissée' . ($bilan['ignorees'] > 1 ? 's' : '') . ' telle' . ($bilan['ignorees'] > 1 ? 's' : '') . ' quelle' . ($bilan['ignorees'] > 1 ? 's' : '') : null,
        ]);

        return redirect()->route('campaigns.show', $campaign)
            ->with('ok', 'Import terminé : ' . implode(', ', $morceaux) . '. Tout est en brouillon, à relire avant de programmer.');
    }
}
