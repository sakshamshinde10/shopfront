import Alpine from 'alpinejs';
import home from './home';
import workspace from './workspace';

Alpine.data('home', home);
Alpine.data('workspace', workspace);

window.Alpine = Alpine;
Alpine.start();
