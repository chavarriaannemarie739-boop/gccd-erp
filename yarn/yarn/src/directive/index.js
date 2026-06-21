import preventReClick from './common/preventReClick.js'
import hasPermi from './common/hasPermi.js'

export default function directive(app) {
  app.directive('preventReClick', preventReClick)
  app.directive('hasPermi', hasPermi)
}
