import '../../css/form.css';
import { enhance } from './render.js';

const form = document.querySelector('form[data-form-id]');
if (form) enhance(form);
