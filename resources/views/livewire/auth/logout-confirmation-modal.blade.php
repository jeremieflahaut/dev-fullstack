<div>
    <button class="btn btn-danger" wire:click="openModal">Déconnexion</button>

    @if ($isOpen)
        <div class="modal fade show" style="display: block;">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirmation de la Déconnexion</h5>
                        <button type="button" class="btn-close" aria-label="Close" wire:click="closeModal"></button>
                    </div>
                    <div class="modal-body">
                        Êtes-vous sûr de vouloir vous déconnecter ?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="closeModal">Annuler</button>
                        <button type="button" class="btn btn-danger" wire:click="logout">Déconnexion</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
