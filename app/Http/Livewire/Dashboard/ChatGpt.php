<?php

declare(strict_types=1);

namespace App\Http\Livewire\Dashboard;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Livewire\Component;
use OpenAI\Laravel\Facades\OpenAI;

class ChatGpt extends Component
{
    public string $prompt = '';

    public array $messages = [
        [
            'role' => 'system',
            'content' => 'Tu es rédacteur du blog personnel d\'un développeur full-stack laravel.
            rajoute des retours à la ligne dans tes réponses',
        ],
    ];

    public function fetchResponse()
    {
        //Redige un article de ton choix en markdown pour mon blog. Fait des paragraphes et des retours a la ligne quand c'est nécéssaire

        try {

            if (! empty($this->prompt)) {
                $this->messages[] = [
                    'role' => 'user',
                    'content' => $this->prompt,
                ];

                $response = OpenAI::chat()->create([
                    'model' => 'gpt-3.5-turbo',
                    'messages' => $this->messages,
                    'temperature' => 0.8,
                    'max_tokens' => 1000,
                ]);

                $response = $response->toArray()['choices'][0]['message']['content'];

                $this->messages[] = ['role' => 'assistant', 'content' => $response];

                $this->prompt = '';
            }

        } catch (\Exception $e) {
            $this->messages[] = ['role' => 'assistant', 'content' => 'Erreur lors de la récupération de la réponse : '.$e->getMessage()];
        }
    }

    public function render(): View|Application|Factory|\Illuminate\Contracts\Foundation\Application
    {
        return view('livewire.dashboard.chat-gpt');
    }
}
