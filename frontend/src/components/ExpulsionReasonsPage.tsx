import { useCallback, useEffect, useState, type FormEvent } from "react"

import { api, type ExpulsionReason } from "../lib/api"

type ReasonForm = {
  name: string
  description: string
  suspension_matches: string
}

const emptyForm: ReasonForm = {
  name: "",
  description: "",
  suspension_matches: "0",
}

export default function ExpulsionReasonsPage() {
  const [reasons, setReasons] = useState<ExpulsionReason[]>([])
  const [form, setForm] = useState<ReasonForm>(emptyForm)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState("")
  const [notice, setNotice] = useState("")

  const loadReasons = useCallback(async () => {
    setLoading(true)
    setError("")
    try {
      setReasons(await api.get<ExpulsionReason[]>("/expulsion-reasons"))
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudieron cargar los motivos de expulsión.",
      )
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void loadReasons()
  }, [loadReasons])

  const resetForm = () => {
    setEditingId(null)
    setForm(emptyForm)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError("")
    setNotice("")
    const payload = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      suspension_matches: Number(form.suspension_matches),
    }
    try {
      if (editingId === null) {
        await api.post("/expulsion-reasons", payload)
        setNotice("Motivo de expulsión creado.")
      } else {
        await api.post(`/expulsion-reasons/${editingId}`, payload)
        setNotice("Motivo de expulsión actualizado.")
      }
      resetForm()
      await loadReasons()
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo guardar el motivo.",
      )
    } finally {
      setSaving(false)
    }
  }

  const edit = (reason: ExpulsionReason) => {
    setEditingId(reason.id)
    setForm({
      name: reason.name,
      description: reason.description ?? "",
      suspension_matches: String(reason.suspension_matches),
    })
    setNotice("")
    setError("")
  }

  const toggleActive = async (reason: ExpulsionReason) => {
    setError("")
    setNotice("")
    try {
      await api.post(`/expulsion-reasons/${reason.id}`, {
        is_active: !Boolean(Number(reason.is_active)),
      })
      setNotice(
        reason.is_active
          ? "Motivo desactivado; se conserva el historial."
          : "Motivo activado.",
      )
      await loadReasons()
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo cambiar el estado.",
      )
    }
  }

  const remove = async (reason: ExpulsionReason) => {
    if (
      !window.confirm(
        `¿Eliminar "${reason.name}"? Si ya fue utilizado, se desactivará para conservar las sanciones históricas.`,
      )
    ) {
      return
    }
    setError("")
    setNotice("")
    try {
      const result = await api.delete<{ action: "deleted" | "deactivated" }>(
        `/expulsion-reasons/${reason.id}`,
      )
      setNotice(
        result.action === "deactivated"
          ? "El motivo ya se utilizó y fue desactivado para conservar el historial."
          : "Motivo eliminado.",
      )
      if (editingId === reason.id) resetForm()
      await loadReasons()
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo eliminar o desactivar el motivo.",
      )
    }
  }

  return (
    <>
      <div className="app-page-heading">
        <div>
          <span className="app-kicker">ADMINISTRACIÓN DISCIPLINARIA</span>
          <h1>Motivos de expulsión</h1>
          <p>Configura los motivos y los partidos de sanción que generan.</p>
        </div>
        <button
          className="app-button secondary"
          onClick={() => void loadReasons()}
          disabled={loading}
        >
          {loading ? "Actualizando..." : "Actualizar"}
        </button>
      </div>

      {(error || notice) && (
        <div className={error ? "app-alert" : "app-notice"} role={error ? "alert" : "status"}>
          <strong>{error ? "No se pudo completar la operación" : "Listo"}</strong>
          <span>{error || notice}</span>
        </div>
      )}

      <section className="app-panel app-reason-form-panel">
        <header className="app-panel-head">
          <div>
            <h2>{editingId === null ? "Agregar motivo" : "Editar motivo"}</h2>
            <p>Las sanciones creadas conservan una copia del nombre y duración asignados.</p>
          </div>
        </header>
        <form className="app-reason-form" onSubmit={submit}>
          <label className="app-field">
            <span>Nombre</span>
            <input
              required
              maxLength={150}
              value={form.name}
              onChange={(event) =>
                setForm((current) => ({ ...current, name: event.target.value }))
              }
            />
          </label>
          <label className="app-field">
            <span>Partidos de suspensión</span>
            <input
              required
              type="number"
              min="0"
              max="65535"
              value={form.suspension_matches}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  suspension_matches: event.target.value,
                }))
              }
            />
          </label>
          <label className="app-field app-reason-description">
            <span>Descripción</span>
            <input
              maxLength={500}
              value={form.description}
              onChange={(event) =>
                setForm((current) => ({
                  ...current,
                  description: event.target.value,
                }))
              }
            />
          </label>
          <div className="app-reason-form-actions">
            {editingId !== null && (
              <button
                className="app-button secondary"
                type="button"
                onClick={resetForm}
              >
                Cancelar
              </button>
            )}
            <button className="app-button" type="submit" disabled={saving}>
              {saving
                ? "Guardando..."
                : editingId === null
                  ? "Crear motivo"
                  : "Guardar cambios"}
            </button>
          </div>
        </form>
      </section>

      <section className="app-panel app-reason-list-panel">
        <header className="app-panel-head">
          <div>
            <h2>Motivos configurados</h2>
            <p>Los motivos inactivos no aparecen al registrar nuevas expulsiones.</p>
          </div>
        </header>
        {loading ? (
          <div className="app-loading">Cargando motivos de expulsión...</div>
        ) : reasons.length === 0 ? (
          <div className="app-empty">
            <strong>No hay motivos configurados</strong>
            <p>Agrega un motivo para habilitar el registro de expulsiones.</p>
          </div>
        ) : (
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Motivo</th>
                  <th>Suspensión</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {reasons.map((reason) => (
                  <tr key={reason.id}>
                    <td>
                      <strong>{reason.name}</strong>
                      {reason.description && <small className="app-reason-description-text">{reason.description}</small>}
                    </td>
                    <td>{reason.suspension_matches} partido(s)</td>
                    <td>
                      <span className={`app-status ${Number(reason.is_active) ? "active" : ""}`}>
                        {Number(reason.is_active) ? "Activo" : "Inactivo"}
                      </span>
                    </td>
                    <td>
                      <div className="app-row-actions">
                        <button onClick={() => edit(reason)}>Editar</button>
                        <button onClick={() => void toggleActive(reason)}>
                          {Number(reason.is_active) ? "Desactivar" : "Activar"}
                        </button>
                        <button
                          className="danger-text"
                          onClick={() => void remove(reason)}
                        >
                          Eliminar
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  )
}
