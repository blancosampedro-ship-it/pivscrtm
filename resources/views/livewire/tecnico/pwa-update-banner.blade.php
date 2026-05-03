<?php

use Livewire\Volt\Component;

new class extends Component {
    public bool $show = false;

    protected $listeners = ['pwa:update-available' => 'showBanner'];

    public function showBanner(): void
    {
        $this->show = true;
    }

    public function reload(): void
    {
        $this->dispatch('pwa:reload-now');
    }
}; ?>

<div x-data="{
    init() {
        window.addEventListener('pwa:update-available', () => {
            Livewire.dispatch('pwa:update-available');
        });

        window.addEventListener('pwa:reload-now', () => {
            if (window.winfinPivUpdateServiceWorker) {
                window.winfinPivUpdateServiceWorker(true);
                return;
            }

            window.location.reload();
        });
    }
}"
@if (! $show) style="display:none" @endif
class="fixed bottom-0 left-0 right-0 bg-primary-60 text-ink-on_color px-4 py-3 flex items-center justify-between shadow-lg z-50">
    <div class="flex-1 text-md font-medium">
        Nueva versión disponible
    </div>
    <button wire:click="reload"
            class="bg-white text-primary-60 px-4 py-2 font-medium tap-target ml-3">
        Recargar
    </button>
</div>
