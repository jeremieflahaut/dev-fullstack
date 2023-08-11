<div>

    <div class="mb-3">
        @foreach($messages as $message)

            @if($message['role'] == 'user')
                <div class="d-flex justify-content-end mb-3">
                    <div class="bg-warning p-2 rounded max-w-50">
                        {!! $message['content'] !!}
                    </div>
                </div>
            @endif

            @if($message['role'] == 'assistant')
                <div class="d-flex justify-content-end mb-3 ">
                    <div class="p-2 rounded max-w-50 language-">
                        {!!nl2br(e($message['content']))!!}
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="input-group mb-3">
        <input type="text" class="form-control mr-2" placeholder="Prompt pour ChatGpt ..." wire:model="prompt" wire:keydown.enter="fetchResponse">
        <button class="btn btn-outline-secondary" wire:click="fetchResponse" wire:loading.remove>
            <i class="fa-regular fa-comment"></i>
        </button>
        <button class="btn btn-outline-secondary" type="button" wire:loading.attr="disabled" wire:loading
                wire:target="fetchResponse">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span class="visually-hidden">Loading...</span>
        </button>
    </div>
</div>
