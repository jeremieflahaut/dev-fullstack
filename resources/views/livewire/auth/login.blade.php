<div>
    <div class="card">
        <div class="card-header">Connexion</div>
        <div class="card-body">
            @if (session()->has('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif
            <form wire:submit.prevent="login">
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">Adresse e-mail</label>
                    <input wire:model="email" type="email" class="form-control" name="email" required autofocus>
                    @error('email')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Mot de passe</label>
                    <input wire:model="password" type="password" class="form-control" name="password" required>
                    @error('password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-primary">Se connecter</button>
            </form>
        </div>
    </div>
</div>
