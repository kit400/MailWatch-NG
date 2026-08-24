/**
 * Modern ECharts Line/Area/Bar Chart Implementation
 * Matching https://ip.space.ua/info.php & EFA-NG Design
 */

var lineColors = {
  mailColor: '#1f6cb0',   // Primary Blue
  spamColor: '#f59e0b',   // Amber
  virusColor: '#dc2626',  // Red
  volumeColor: '#0284c7', // Sky Blue
  mcpColor: '#8b5cf6',    // Purple
  hamColor: '#10b981'     // Green
};

function printLineGraph(chartId, settings) {
  var chartDom = document.getElementById(chartId);
  if (!chartDom) return;

  if (typeof echarts === 'undefined') {
    console.error('ECharts library not loaded');
    return;
  }

  var existingChart = echarts.getInstanceByDom(chartDom);
  if (existingChart) {
    existingChart.dispose();
  }

  var myChart = echarts.init(chartDom);

  var isHeaderTraffic = (chartId === 'trafficgraph');
  var labels = settings.chartLabels || [];
  var seriesList = [];
  var legendData = [];

  var palette = ['#1f6cb0', '#f59e0b', '#dc2626', '#10b981', '#8b5cf6', '#06b6d4'];

  if (settings.chartNumericData && settings.chartNumericData.length > 0) {
    var colorIdx = 0;
    for (var axis = 0; axis < settings.chartNumericData.length; axis++) {
      var axisData = settings.chartNumericData[axis];
      for (var s = 0; s < axisData.length; s++) {
        var sName = (settings.chartDataLabels && settings.chartDataLabels[axis] && settings.chartDataLabels[axis][s]) 
                    ? settings.chartDataLabels[axis][s] 
                    : ('Series ' + (s + 1));
        legendData.push(sName);

        var sType = (settings.types && settings.types[axis] && settings.types[axis][s]) 
                    ? settings.types[axis][s] 
                    : 'line';
        if (sType !== 'bar') {
          sType = 'line';
        }

        var colKey = '';
        if (settings.colors && settings.colors[axis] && settings.colors[axis][s]) {
          colKey = settings.colors[axis][s];
        }
        var color = lineColors[colKey] || palette[colorIdx % palette.length];
        colorIdx++;

        var isFilled = (settings.fillBelowLine && settings.fillBelowLine[axis]);

        var seriesConfig = {
          name: sName,
          type: sType,
          data: axisData[s],
          smooth: 0.35,
          showSymbol: !isHeaderTraffic && (sType === 'line'),
          symbolSize: 4,
          itemStyle: {
            color: color,
            borderRadius: sType === 'bar' ? [4, 4, 0, 0] : 0
          },
          lineStyle: {
            color: color,
            width: isHeaderTraffic ? 2 : 2.5
          }
        };

        if (isFilled || isHeaderTraffic) {
          seriesConfig.areaStyle = {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
              { offset: 0, color: hexToRgba(color, 0.45) },
              { offset: 1, color: hexToRgba(color, 0.02) }
            ])
          };
        }

        seriesList.push(seriesConfig);
      }
    }
  }

  var option = {
    title: {
      text: settings.chartTitle || '',
      left: 'center',
      top: 6,
      textStyle: {
        fontSize: 15,
        fontWeight: 700,
        color: '#0f172a'
      }
    },
    tooltip: {
      trigger: 'axis',
      axisPointer: {
        type: 'cross',
        label: {
          backgroundColor: '#1f6cb0'
        },
        lineStyle: {
          color: '#94a3b8',
          type: 'dashed'
        }
      },
      backgroundColor: 'rgba(15, 23, 42, 0.92)',
      borderColor: '#334155',
      borderWidth: 1,
      padding: [8, 12],
      textStyle: {
        color: '#f8fafc',
        fontSize: 12
      }
    },
    legend: {
      show: !isHeaderTraffic && legendData.length > 1,
      data: legendData,
      top: settings.chartTitle ? 32 : 10,
      right: 15,
      textStyle: {
        color: '#475569',
        fontSize: 11
      }
    },
    grid: {
      left: isHeaderTraffic ? 8 : 45,
      right: isHeaderTraffic ? 8 : 25,
      bottom: isHeaderTraffic ? 6 : (labels.length > 20 ? 38 : 28),
      top: isHeaderTraffic ? 8 : (settings.chartTitle ? 55 : 35),
      containLabel: true
    },
    xAxis: {
      type: 'category',
      boundaryGap: seriesList.some(function(s) { return s.type === 'bar'; }),
      data: labels,
      axisLine: {
        lineStyle: { color: '#cbd5e1' }
      },
      axisLabel: {
        color: '#64748b',
        fontSize: isHeaderTraffic ? 9 : 10.5,
        interval: isHeaderTraffic ? 'auto' : (labels.length > 24 ? 'auto' : 0),
        rotate: !isHeaderTraffic && labels.length > 24 ? 45 : 0
      }
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      axisLine: {
        show: false
      },
      axisTick: {
        show: false
      },
      splitLine: {
        lineStyle: {
          color: '#f1f5f9',
          type: 'dashed'
        }
      },
      axisLabel: {
        color: '#64748b',
        fontSize: isHeaderTraffic ? 9 : 10.5
      }
    },
    series: seriesList
  };

  myChart.setOption(option);

  function triggerResize() {
    if (myChart && chartDom) {
      myChart.resize();
    }
  }

  setTimeout(triggerResize, 50);
  setTimeout(triggerResize, 200);
  setTimeout(triggerResize, 500);

  window.addEventListener('resize', triggerResize);
  window.addEventListener('load', triggerResize);
  document.addEventListener('DOMContentLoaded', triggerResize);
}

function hexToRgba(hex, opacity) {
  hex = hex.replace('#', '');
  if (hex.length === 3) {
    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
  }
  var r = parseInt(hex.substring(0, 2), 16) || 0;
  var g = parseInt(hex.substring(2, 4), 16) || 0;
  var b = parseInt(hex.substring(4, 6), 16) || 0;
  return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + opacity + ')';
}
