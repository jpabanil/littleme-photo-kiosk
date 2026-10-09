import http from 'k6/http'
import { check } from 'k6'

export const options = {
  scenarios: {
    guests: {
      executor: 'shared-iterations',
      vus: 30,
      iterations: 30,
      maxDuration: '3m',
    },
  },
}

const photo = open('./photo.jpg', 'b')

export default function () {
  const base = __ENV.BASE_URL || 'http://127.0.0.1:8000'
  const response = http.post(`${base}/api/generate`, {
    consent: 'true',
    gender: 'girl',
    photo: http.file(photo, 'selfie.jpg', 'image/jpeg'),
  })

  check(response, {
    'generated': (result) => result.status === 200,
  })
}
