import { Application } from "/vendor/@hotwired/stimulus/stimulus.index.js";
import SandboxController from "./controllers/sandbox_controller.js";
import HighlightController from "./controllers/highlight_controller.js";
import TocController from "./controllers/toc_controller.js";

const application = Application.start();
application.register("sandbox", SandboxController);
application.register("highlight", HighlightController);
application.register("toc", TocController);
