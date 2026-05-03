import './bootstrap';
import { registerSW } from 'virtual:pwa-register';

const updateServiceWorker = registerSW({
	onNeedRefresh() {
		window.dispatchEvent(new CustomEvent('pwa:update-available'));
	},
});

window.winfinPivUpdateServiceWorker = updateServiceWorker;
