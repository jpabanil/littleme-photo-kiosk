import { useEffect, useRef, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { generatePortrait, type Gender, type GenerateResult } from './api'

type Step = 'consent' | 'select' | 'camera' | 'generating' | 'result' | 'error'

const steps = [
  { id: 'consent', label: 'Agree' },
  { id: 'select', label: 'Choose' },
  { id: 'camera', label: 'Photo' },
  { id: 'generating', label: 'Create' },
] as const

function wait(ms: number) {
  return new Promise((resolve) => window.setTimeout(resolve, ms))
}

async function snapshot(video: HTMLVideoElement): Promise<Blob> {
  const width = video.videoWidth
  const height = video.videoHeight
  if (!width || !height) {
    throw new Error('The camera is not ready yet. Try again.')
  }

  const scale = Math.min(1, 1024 / Math.max(width, height))
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(width * scale)
  canvas.height = Math.round(height * scale)
  const context = canvas.getContext('2d')
  if (!context) {
    throw new Error('We could not use the camera. Try again.')
  }

  context.translate(canvas.width, 0)
  context.scale(-1, 1)
  context.drawImage(video, 0, 0, canvas.width, canvas.height)

  const blob = await new Promise<Blob | null>((resolve) => {
    canvas.toBlob(resolve, 'image/jpeg', 0.85)
  })
  if (!blob) {
    throw new Error('We could not save that photo. Try again.')
  }
  return blob
}

export default function App() {
  const [step, setStep] = useState<Step>('consent')
  const [gender, setGender] = useState<Gender | null>(null)
  const [photo, setPhoto] = useState<Blob | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [result, setResult] = useState<GenerateResult | null>(null)
  const [status, setStatus] = useState('Please wait…')
  const [errorMessage, setErrorMessage] = useState('')
  const [cameraError, setCameraError] = useState('')
  const [cameraAttempt, setCameraAttempt] = useState(0)
  const [countdown, setCountdown] = useState<number | null>(null)
  const [remaining, setRemaining] = useState(60)
  const videoRef = useRef<HTMLVideoElement>(null)
  const busy = useRef(false)

  useEffect(() => {
    if (step !== 'camera') {
      return
    }

    const video = videoRef.current
    let stream: MediaStream | null = null
    let cancelled = false
    setCameraError('')

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: 'user', width: 1280, height: 720 }, audio: false })
      .then((next) => {
        if (cancelled) {
          next.getTracks().forEach((track) => track.stop())
          return
        }
        stream = next
        if (video) {
          video.srcObject = next
        }
      })
      .catch(() => {
        if (!cancelled) {
          setCameraError('Camera access is off. Allow the camera in the browser, then try again.')
        }
      })

    return () => {
      cancelled = true
      stream?.getTracks().forEach((track) => track.stop())
      if (video) {
        video.srcObject = null
      }
    }
  }, [step, cameraAttempt])

  useEffect(() => {
    if (step !== 'result') {
      return
    }

    let left = 60
    setRemaining(60)
    const timer = window.setInterval(() => {
      left -= 1
      setRemaining(left)
      if (left <= 0) {
        clearGuest()
      }
    }, 1000)
    const stay = () => {
      left = 60
      setRemaining(60)
    }
    window.addEventListener('pointerdown', stay)

    return () => {
      window.clearInterval(timer)
      window.removeEventListener('pointerdown', stay)
    }
  }, [step])

  function clearGuest() {
    setPreview((current) => {
      if (current) {
        URL.revokeObjectURL(current)
      }
      return null
    })
    setPhoto(null)
    setResult(null)
    setGender(null)
    setErrorMessage('')
    setCountdown(null)
    setStep('consent')
  }

  async function runGenerate(blob: Blob, chosen: Gender) {
    if (busy.current) {
      return
    }
    busy.current = true
    setStatus('Please wait…')
    setStep('generating')
    const timer = window.setTimeout(() => setStatus('Generating your image…'), 1000)

    try {
      const next = await generatePortrait(blob, chosen)
      window.clearTimeout(timer)
      setStatus('Your image is ready!')
      await wait(800)
      setResult(next)
      setStep('result')
    } catch (error: unknown) {
      window.clearTimeout(timer)
      setErrorMessage(error instanceof Error ? error.message : 'We could not create your image. Please try again.')
      setStep('error')
    } finally {
      busy.current = false
    }
  }

  async function capture() {
    const video = videoRef.current
    if (!video || countdown !== null || busy.current) {
      return
    }

    for (const value of [3, 2, 1] as const) {
      setCountdown(value)
      await wait(700)
    }
    setCountdown(null)

    try {
      const blob = await snapshot(video)
      setPhoto(blob)
      setPreview((current) => {
        if (current) {
          URL.revokeObjectURL(current)
        }
        return URL.createObjectURL(blob)
      })
    } catch (error: unknown) {
      setErrorMessage(error instanceof Error ? error.message : 'We could not save that photo. Try again.')
      setStep('error')
    }
  }

  const activeIndex = step === 'result' || step === 'error' ? 3 : steps.findIndex((item) => item.id === step)

  return (
    <div className="shell">
      <aside className="rail">
        <div>
          <p className="eyebrow">Photo kiosk</p>
          <h1 className="brand">Little Me</h1>
          <p className="rail-copy">A childhood portrait, made while you wait.</p>
        </div>
        <ol className="steps">
          {steps.map((item, index) => (
            <li key={item.id} className={index === activeIndex ? 'is-active' : index < activeIndex ? 'is-done' : ''}>
              <span>{index + 1}</span>
              {item.label}
            </li>
          ))}
        </ol>
        <p className="rail-note">Your selfie is not stored. The portrait deletes itself after the event.</p>
      </aside>

      <main className="stage">
        {step === 'consent' && (
          <section className="panel">
            <p className="kicker">Before we start</p>
            <h2>A younger you, just for today.</h2>
            <ul className="points">
              <li>We use the photo only to create this picture.</li>
              <li>The selfie itself is not kept.</li>
              <li>The new picture is deleted automatically.</li>
            </ul>
            <button type="button" className="primary" onClick={() => setStep('select')}>
              I agree and continue
            </button>
          </section>
        )}

        {step === 'select' && (
          <section className="panel panel-wide">
            <p className="kicker">One tap</p>
            <h2>Who should we picture?</h2>
            <div className="choices">
              <button type="button" className="choice choice-boy" onClick={() => { setGender('boy'); setStep('camera') }}>
                <span>Boy</span>
                <small>Tap to open the camera</small>
              </button>
              <button type="button" className="choice choice-girl" onClick={() => { setGender('girl'); setStep('camera') }}>
                <span>Girl</span>
                <small>Tap to open the camera</small>
              </button>
            </div>
          </section>
        )}

        {step === 'camera' && (
          <section className="panel panel-wide camera-layout">
            <div>
              <p className="kicker">Look here</p>
              <h2>Center your face, then capture.</h2>
              <p className="help">You get a short countdown. Retake as many times as you like.</p>
            </div>
            <div className="viewfinder">
              {cameraError ? (
                <div className="camera-message">
                  <p>{cameraError}</p>
                  <button type="button" className="primary" onClick={() => setCameraAttempt((value) => value + 1)}>
                    Try the camera again
                  </button>
                </div>
              ) : (
                <>
                  <video ref={videoRef} autoPlay playsInline muted className={preview ? 'is-hidden' : ''} />
                  {preview && <img src={preview} alt="Your captured photo" />}
                  {countdown !== null && <div className="countdown">{countdown}</div>}
                </>
              )}
            </div>
            {!cameraError && (
              <div className="actions">
                {preview ? (
                  <>
                    <button type="button" className="ghost" onClick={() => {
                      setPreview((current) => {
                        if (current) URL.revokeObjectURL(current)
                        return null
                      })
                      setPhoto(null)
                    }}>
                      Retake
                    </button>
                    <button
                      type="button"
                      className="primary"
                      disabled={!photo || !gender}
                      onClick={() => photo && gender && runGenerate(photo, gender)}
                    >
                      Create my portrait
                    </button>
                  </>
                ) : (
                  <button type="button" className="shutter" onClick={capture} disabled={countdown !== null}>
                    Capture
                  </button>
                )}
              </div>
            )}
          </section>
        )}

        {step === 'generating' && (
          <section className="panel waiting" aria-live="polite">
            <div className="orb" />
            <h2>{status}</h2>
            <p className="help">This usually takes a few seconds. Please stay at the screen.</p>
          </section>
        )}

        {step === 'result' && result && preview && (
          <section className="result">
            <article className="polaroid">
              <img src={preview} alt="The photo you just took" />
              <div>
                <p className="kicker">You</p>
                <button type="button" className="ghost" onClick={clearGuest}>Start over</button>
              </div>
            </article>
            <article className="portrait">
              <img src={result.image_url} alt="Your childhood portrait" />
              <aside className="ticket">
                <QRCodeSVG value={result.share_url} size={168} bgColor="#fffaf5" fgColor="#241c18" />
                <p>Scan to save and share</p>
              </aside>
            </article>
            <p className="reset-bar">This screen clears in {remaining}s. Tap anywhere to stay.</p>
          </section>
        )}

        {step === 'error' && (
          <section className="panel">
            <p className="kicker">Let’s try that again</p>
            <h2>We couldn’t finish the portrait.</h2>
            <p className="help">{errorMessage}</p>
            <div className="actions">
              <button
                type="button"
                className="primary"
                onClick={() => {
                  if (photo && gender) {
                    void runGenerate(photo, gender)
                    return
                  }
                  setStep(gender ? 'camera' : 'select')
                }}
              >
                Try again
              </button>
              <button type="button" className="ghost" onClick={clearGuest}>Start over</button>
            </div>
          </section>
        )}
      </main>
    </div>
  )
}
