import '../../css/form.css';
import '../../css/dashboard.css';
import { createBuilder } from './builder.js';

const root = document.querySelector('[data-builder]');
const data = document.querySelector('#builder-data');
if (root && data) createBuilder(root, JSON.parse(data.textContent));
