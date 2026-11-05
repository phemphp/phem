package phem.intellij

import com.intellij.openapi.components.BaseState
import com.intellij.openapi.components.Service
import com.intellij.openapi.components.SimplePersistentStateComponent
import com.intellij.openapi.components.State
import com.intellij.openapi.components.Storage
import com.intellij.openapi.components.service
import com.intellij.openapi.project.Project

/**
 * The settings of the machine: the paths of the executables. The IDE keeps them out of the
 * project, so a project cannot set an executable that the IDE then runs.
 */
@Service(Service.Level.APP)
@State(name = "PhemSettings", storages = [Storage("phem.xml")])
class PhemSettings : SimplePersistentStateComponent<PhemSettings.Options>(Options()) {
    class Options : BaseState() {
        /** The path of a `phemc` binary. Empty: use the bundled server. */
        var phemcPath by string("")

        /** The `node` executable for the bundled server. Empty: `node` from PATH. */
        var nodePath by string("")
    }

    var phemcPath: String
        get() = state.phemcPath.orEmpty().trim()
        set(value) { state.phemcPath = value.trim() }

    var nodePath: String
        get() = state.nodePath.orEmpty().trim()
        set(value) { state.nodePath = value.trim() }

    companion object {
        fun getInstance(): PhemSettings = service()
    }
}

/** The settings of the project. */
@Service(Service.Level.PROJECT)
@State(name = "PhemProjectSettings", storages = [Storage("phem.xml")])
class PhemProjectSettings : SimplePersistentStateComponent<PhemProjectSettings.Options>(Options()) {
    class Options : BaseState() {
        /** The source directory of the `.phem` files, relative to the project root. */
        var sourceDir by string(DEFAULT_SOURCE_DIR)
    }

    var sourceDir: String
        get() = state.sourceDir?.trim()?.ifEmpty { null } ?: DEFAULT_SOURCE_DIR
        set(value) { state.sourceDir = value.trim().ifEmpty { DEFAULT_SOURCE_DIR } }

    companion object {
        const val DEFAULT_SOURCE_DIR = "src"

        fun getInstance(project: Project): PhemProjectSettings = project.service()
    }
}
