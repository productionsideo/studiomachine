<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CalendrierController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CapsuleController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConnexionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login',  [LoginController::class, 'show'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'store'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Demandes reçues
    Route::get('/leads',              [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/export',       [LeadController::class, 'export'])->name('leads.export');
    Route::get('/leads/{lead}',       [LeadController::class, 'show'])->name('leads.show');
    Route::patch('/leads/{lead}',     [LeadController::class, 'update'])->name('leads.update');

    // Performance des capsules
    Route::get('/capsules',           [CapsuleController::class, 'index'])->name('capsules.index');
    Route::get('/capsules/{capsule}', [CapsuleController::class, 'show'])->name('capsules.show');

    // Boîte de réception des commentaires (réseaux sociaux)
    Route::get('/commentaires',                    [CommentController::class, 'index'])->name('comments.index');
    Route::post('/commentaires/{comment}/reply',   [CommentController::class, 'reply'])->name('comments.reply');
    Route::post('/commentaires/{comment}/suggerer',[CommentController::class, 'suggest'])->name('comments.suggest');
    Route::post('/commentaires/{comment}/ecarter', [CommentController::class, 'ignore'])->name('comments.ignore');

    // Assistant Claude — réglages et banc d'essai
    // L'essai est limité : un appel d'API coûte, et le champ est libre.
    Route::get('/assistant',            [AssistantController::class, 'index'])->name('assistant.index');
    Route::patch('/assistant/{client}', [AssistantController::class, 'update'])->name('assistant.update');
    Route::post('/assistant/essai',     [AssistantController::class, 'essai'])
        ->middleware('throttle:20,1')->name('assistant.essai');

    // Comptes Analytics et réseaux sociaux
    Route::get('/integrations',                        [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('/integrations/{client}',              [IntegrationController::class, 'store'])->whereNumber('client')->name('integrations.store');
    Route::delete('/integrations/{integration}',       [IntegrationController::class, 'destroy'])->name('integrations.destroy');

    // Connexion d'un compte par OAuth (Meta, Google, TikTok). Le client peut
    // brancher ses propres comptes ; publier reste réservé à l'équipe.
    Route::get('/integrations/{client}/connecter/{fournisseur}', [ConnexionController::class, 'rediriger'])
        ->where('fournisseur', 'meta|youtube|tiktok')->name('connexion.rediriger');
    Route::get('/integrations/{fournisseur}/retour', [ConnexionController::class, 'retour'])
        ->where('fournisseur', 'meta|youtube|tiktok')->name('connexion.retour');
    Route::post('/integrations/choisir', [ConnexionController::class, 'choisir'])->name('connexion.choisir');

    // Publication — rédiger et programmer : l'équipe seulement.
    Route::middleware('admin')->group(function () {
        Route::get('/publications/nouvelle',          [PostController::class, 'create'])->name('posts.create');
        Route::post('/publications',                  [PostController::class, 'store'])->name('posts.store');
        Route::get('/publications/{post}/modifier',   [PostController::class, 'edit'])->name('posts.edit');
        Route::patch('/publications/{post}',          [PostController::class, 'update'])->name('posts.update');
        Route::delete('/publications/{post}',         [PostController::class, 'destroy'])->name('posts.destroy');
        Route::post('/publications/{post}/dupliquer', [PostController::class, 'dupliquer'])->name('posts.dupliquer');
        Route::post('/publications/{post}/cibles/{cible}/relancer', [PostController::class, 'relancer'])->name('posts.relancer');
        Route::post('/publications/{post}/cibles/{cible}/annuler',  [PostController::class, 'annulerCible'])->name('posts.annuler');

        Route::get('/campagnes/nouvelle',            [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campagnes',                    [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campagnes/{campaign}/modifier', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::patch('/campagnes/{campaign}',        [CampaignController::class, 'update'])->name('campaigns.update');

        // Les morceaux d'un gros fichier arrivent l'un après l'autre : le
        // plafond de débit doit laisser passer une vidéo de 1 Go (128 morceaux).
        Route::post('/medias/{client}/morceau', [MediaController::class, 'morceau'])
            ->middleware('throttle:300,1')->name('medias.morceau');
        Route::delete('/medias/{media}',        [MediaController::class, 'destroy'])->name('medias.destroy');
    });

    // Publication — consulter : l'équipe et les clients.
    Route::get('/calendrier',             [CalendrierController::class, 'index'])->name('calendrier.index');
    Route::get('/publications/{post}',    [PostController::class, 'show'])->name('posts.show');
    Route::get('/campagnes',              [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('/campagnes/{campaign}',   [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('/medias',                 [MediaController::class, 'index'])->name('medias.index');

    // Gestion des clients et des accès — équipe Studio Machine seulement
    Route::middleware('admin')->group(function () {

        // Les accès à la plateforme : administrateurs et contacts clients.
        Route::get('/acces',                    [UserController::class, 'index'])->name('users.index');
        Route::post('/acces',                   [UserController::class, 'store'])->name('users.store');
        Route::patch('/acces/{user}/etat',      [UserController::class, 'toggle'])->name('users.toggle');
        Route::patch('/acces/{user}/motdepasse',[UserController::class, 'password'])->name('users.password');
        Route::delete('/acces/{user}',          [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/clients',                 [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/nouveau',         [ClientController::class, 'create'])->name('clients.create');
        Route::post('/clients',                [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}',        [ClientController::class, 'edit'])->name('clients.edit');
        Route::patch('/clients/{client}',      [ClientController::class, 'update'])->name('clients.update');
        Route::post('/clients/{client}/cle',   [ClientController::class, 'regenerateKey'])->name('clients.key');
        Route::post('/clients/{client}/acces', [ClientController::class, 'addUser'])->name('clients.adduser');
    });
});
