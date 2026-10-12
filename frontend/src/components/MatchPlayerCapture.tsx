import type {
  ExpulsionReason,
  MatchDisciplinaryEvent,
  MatchGoal,
  MatchReport,
  MatchReportPlayer,
} from "../lib/api"

type PlayerEvent = MatchReport["disciplinary_events"][number]

type Props = {
  player: MatchReportPlayer
  attended: boolean
  goals: MatchGoal[]
  events: PlayerEvent[]
  reasons: ExpulsionReason[]
  onAttendanceChange: (playerId: number, attended: boolean) => void
  onGoalCountChange: (player: MatchReportPlayer, count: number) => void
  onEventAdd: (
    player: MatchReportPlayer,
    type: MatchDisciplinaryEvent["event_type"],
  ) => void
  onEventChange: (event: PlayerEvent) => void
  onEventRemove: (clientKey: string) => void
}

export default function MatchPlayerCapture({
  player,
  attended,
  goals,
  events,
  reasons,
  onAttendanceChange,
  onGoalCountChange,
  onEventAdd,
  onEventChange,
  onEventRemove,
}: Props) {
  const playerName = `${player.first_name} ${player.last_name}`

  return (
    <article className="app-roster-player">
      <div className="app-roster-player-head">
        <div className="app-roster-player-name">
          <span className="app-roster-number">
            {player.jersey_number == null ? "—" : `#${player.jersey_number}`}
          </span>
          <strong>{playerName}</strong>
        </div>
        <label className="app-attendance-control">
          <span>Asistencia</span>
          <input
            type="checkbox"
            className="app-attendance-checkbox"
            aria-label={`Asistencia de ${playerName}`}
            checked={attended}
            onChange={(event) =>
              onAttendanceChange(player.tournament_player_id, event.target.checked)
            }
          />
          <span>{attended ? "Asistió" : "No asistió"}</span>
        </label>
      </div>

      <div className="app-player-capture-group">
        <label className="app-goal-count-field">
          <span>Goles anotados</span>
          <input
            type="number"
            min="0"
            step="1"
            inputMode="numeric"
            aria-label={`Goles anotados por ${playerName}`}
            value={goals.length}
            onChange={(event) =>
              onGoalCountChange(player, Math.max(0, Number(event.target.value) || 0))
            }
          />
        </label>
      </div>

      <div className="app-player-capture-group">
        <div className="app-player-capture-title">
          <span>Amonestaciones y expulsiones ({events.length})</span>
          <div className="app-player-discipline-actions">
            <button
              className="app-player-add-button"
              type="button"
              onClick={() => onEventAdd(player, "YELLOW_CARD")}
            >
              + Amarilla
            </button>
            <button
              className="app-player-add-button red"
              type="button"
              onClick={() => onEventAdd(player, "DIRECT_RED")}
            >
              + Expulsión
            </button>
          </div>
        </div>
        {events.length ? (
          <div className="app-player-event-list">
            {events.map((event, index) => {
              const locked = event.suspension_id != null
              const listedReason = reasons.some(
                (reason) => reason.id === event.expulsion_reason_id,
              )
              return (
                <div className="app-player-event-row" key={event.client_key}>
                  <label className="app-inline-field">
                    <span>Evento {index + 1}</span>
                    <select
                      value={event.event_type}
                      disabled={locked}
                      onChange={(change) =>
                        onEventChange({
                          ...event,
                          event_type: change.target.value as MatchDisciplinaryEvent["event_type"],
                          expulsion_reason_id:
                            change.target.value === "YELLOW_CARD"
                              ? null
                              : event.expulsion_reason_id,
                        })
                      }
                    >
                      <option value="YELLOW_CARD">Tarjeta amarilla</option>
                      <option value="SECOND_YELLOW_RED">Segunda amarilla y roja</option>
                      <option value="DIRECT_RED">Roja directa</option>
                    </select>
                  </label>
                  {event.event_type !== "YELLOW_CARD" && (
                    <label className="app-inline-field app-player-reason">
                      <span>Motivo de expulsión</span>
                      <select
                        required
                        value={event.expulsion_reason_id ?? ""}
                        disabled={locked}
                        onChange={(change) =>
                          onEventChange({
                            ...event,
                            expulsion_reason_id: Number(change.target.value) || null,
                          })
                        }
                      >
                        <option value="">Seleccionar motivo</option>
                        {!listedReason && event.expulsion_reason_id && (
                          <option value={event.expulsion_reason_id}>
                            {event.expulsion_reason_name ?? "Motivo histórico"}
                          </option>
                        )}
                        {reasons.map((reason) => (
                          <option key={reason.id} value={reason.id}>
                            {reason.name} · {reason.suspension_matches} partidos
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                  <label className="app-inline-field app-player-notes">
                    <span>Observaciones</span>
                    <input
                      maxLength={500}
                      value={event.notes ?? ""}
                      disabled={locked}
                      onChange={(change) =>
                        onEventChange({ ...event, notes: change.target.value })
                      }
                    />
                  </label>
                  {locked && (
                    <span className="app-suspension-note">
                      Sanción: {event.remaining_matches} pendientes de {event.total_matches}
                    </span>
                  )}
                  <button
                    className="app-remove-event"
                    type="button"
                    disabled={locked}
                    aria-label={`Quitar evento ${index + 1} de ${playerName}`}
                    onClick={() => onEventRemove(event.client_key)}
                  >
                    Quitar
                  </button>
                </div>
              )
            })}
          </div>
        ) : (
          <p className="app-player-empty-line">Sin tarjetas registradas</p>
        )}
      </div>
    </article>
  )
}
