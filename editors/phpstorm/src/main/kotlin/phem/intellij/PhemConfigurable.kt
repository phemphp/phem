package phem.intellij

import com.intellij.openapi.fileChooser.FileChooserDescriptorFactory
import com.intellij.openapi.options.BoundConfigurable
import com.intellij.openapi.project.Project
import com.intellij.openapi.ui.DialogPanel
import com.intellij.ui.dsl.builder.AlignX
import com.intellij.ui.dsl.builder.bindText
import com.intellij.ui.dsl.builder.panel

/** Settings | Languages & Frameworks | Phem. */
class PhemConfigurable(private val project: Project) : BoundConfigurable("Phem") {
    private val settings = PhemSettings.getInstance()
    private val projectSettings = PhemProjectSettings.getInstance(project)

    override fun createPanel(): DialogPanel = panel {
        group("Language Server") {
            row("phemc path:") {
                textFieldWithBrowseButton(
                    FileChooserDescriptorFactory.createSingleFileNoJarsDescriptor().withTitle("Select phemc"),
                    project,
                )
                    .bindText(settings::phemcPath)
                    .align(AlignX.FILL)
                    .comment("Optional. When it is set, the plugin runs <code>phemc lsp</code>. Empty: the plugin runs the bundled server with Node.js.")
            }
            row("Node.js path:") {
                textFieldWithBrowseButton(
                    FileChooserDescriptorFactory.createSingleFileNoJarsDescriptor().withTitle("Select Node.js"),
                    project,
                )
                    .bindText(settings::nodePath)
                    .align(AlignX.FILL)
                    .comment("The <code>node</code> executable for the bundled server. Empty: <code>node</code> from PATH.")
            }
        }
        group("Project") {
            row("Source directory:") {
                textField()
                    .bindText(projectSettings::sourceDir)
                    .align(AlignX.FILL)
                    .comment("The directory of the <code>.phem</code> files, relative to the project root (the nearest directory with <code>composer.json</code>).")
            }
        }
    }

    override fun apply() {
        super.apply()
        PhemLspIntegrationProvider.restartAll()
    }
}
