package phem.intellij

import com.google.gson.JsonObject
import com.intellij.openapi.actionSystem.ActionManager
import com.intellij.openapi.actionSystem.ActionPlaces
import com.intellij.openapi.fileEditor.FileEditorManager
import com.intellij.openapi.fileEditor.OpenFileDescriptor
import com.intellij.openapi.vfs.VirtualFile
import com.intellij.platform.lsp.api.LspClient
import com.intellij.platform.lsp.api.customization.LspCodeLensSupport
import org.eclipse.lsp4j.Command
import java.awt.event.MouseEvent

/**
 * Shows the usages of a declaration when the user clicks its usage count (a code lens of the
 * server, for example "2 usages").
 *
 * The command of the lens is `phem.showReferences`. Its arguments are the URI of the document,
 * the position of the declaration, and the locations of the references. The plugin puts the
 * caret on the declaration and runs **Show Usages**. The IDE then asks the server for the
 * references, and shows them in a popup. Click one to go to it.
 */
class PhemCodeLensSupport : LspCodeLensSupport() {
    override fun codeLensClicked(lspClient: LspClient, contextFile: VirtualFile, command: Command, mouseEvent: MouseEvent?) {
        if (command.command != SHOW_REFERENCES) {
            super.codeLensClicked(lspClient, contextFile, command, mouseEvent)
            return
        }

        val position = command.arguments?.getOrNull(1) as? JsonObject ?: return
        val line = position.get("line")?.asInt ?: return
        val character = position.get("character")?.asInt ?: return

        // The LSP character counts UTF-16 code units, as the column of the IDE.
        val project = lspClient.project
        val descriptor = OpenFileDescriptor(project, contextFile, line, character)
        val editor = FileEditorManager.getInstance(project).openTextEditor(descriptor, true) ?: return

        val action = ActionManager.getInstance().getAction("ShowUsages") ?: return
        ActionManager.getInstance().tryToExecute(action, mouseEvent, editor.contentComponent, ActionPlaces.EDITOR_INLAY, true)
    }

    companion object {
        const val SHOW_REFERENCES = "phem.showReferences"
    }
}
