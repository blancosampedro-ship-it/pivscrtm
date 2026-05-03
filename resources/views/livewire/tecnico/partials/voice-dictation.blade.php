<button type="button"
        x-data="{
            recording: false,
            recognition: null,
            init() {
                if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                    const SpeechRecognitionClass = window.SpeechRecognition || window.webkitSpeechRecognition;
                    this.recognition = new SpeechRecognitionClass();
                    this.recognition.lang = 'es-ES';
                    this.recognition.interimResults = false;
                    this.recognition.continuous = false;
                    this.recognition.onresult = (event) => {
                        const text = Array.from(event.results).map((result) => result[0].transcript).join(' ');
                        $wire.set('notas', ($wire.notas ? $wire.notas + ' ' : '') + text);
                    };
                    this.recognition.onend = () => { this.recording = false; };
                }
            },
            toggle() {
                if (! this.recognition) {
                    alert('Tu navegador no soporta dictado por voz. Escribe a mano.');
                    return;
                }
                if (this.recording) {
                    this.recognition.stop();
                } else {
                    this.recognition.start();
                    this.recording = true;
                }
            }
        }"
        @click="toggle"
        :class="recording ? 'bg-error text-ink-on_color' : 'bg-layer-1 text-ink-primary'"
        class="w-full min-h-16 px-4 text-md font-medium border border-line-subtle flex items-center justify-center gap-2">
    <span x-show="!recording">🎤 Dictar nota</span>
    <span x-show="recording">■ Detener (grabando...)</span>
</button>