// Starts Stimulus with the controllers of controllers.json: phem and its headless UI controllers.
// window.Stimulus lets the browser tests wait until the lazy controllers connect.
import { startStimulusApp } from '@symfony/stimulus-bundle'
import './styles/app.css'

window.Stimulus = startStimulusApp()
