import { startGround } from './ground-offline';
import { startSignaturePads } from './signature';

document.addEventListener('DOMContentLoaded', () => {
    startGround();
    startSignaturePads();
});
