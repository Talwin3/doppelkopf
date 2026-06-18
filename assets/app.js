import { registerReactControllerComponents } from '@symfony/ux-react';
import './stimulus_bootstrap.js';
import './styles/app.css';
import { toast } from './toast.js';

window.__toast = toast;

registerReactControllerComponents(require.context('./react/controllers', true, /\.(j|t)sx?$/));